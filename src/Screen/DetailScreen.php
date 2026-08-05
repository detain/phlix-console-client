<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\CastMember;
use Phlix\Console\Api\Dto\CrewMember;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaRatings;
use Phlix\Console\Api\Dto\Rating;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Media\PosterCardFactory;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\CastRequestedMsg;
use Phlix\Console\Msg\ChildPosterLoadedMsg;
use Phlix\Console\Msg\ChildrenFailedMsg;
use Phlix\Console\Msg\ChildrenLoadedMsg;
use Phlix\Console\Msg\DetailFailedMsg;
use Phlix\Console\Msg\DetailLoadedMsg;
use Phlix\Console\Msg\DetailPosterLoadedMsg;
use Phlix\Console\Msg\FavoriteToggleFailedMsg;
use Phlix\Console\Msg\FavoriteToggledMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\PlayRequestedMsg;
use Phlix\Console\Msg\RatingSetFailedMsg;
use Phlix\Console\Msg\RatingSetMsg;
use Phlix\Console\Msg\RatingsLoadedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Msg\WatchedToggleFailedMsg;
use Phlix\Console\Msg\WatchedToggledMsg;
use Phlix\Console\Store\MediaRange;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\RatingBadge;
use Phlix\Console\Ui\UserRatingPicker;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Gallery\PosterGrid;
use SugarCraft\Shine\Renderer;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Style;


/**
 * A single item's detail, in one of two modes decided by the loaded item:
 *
 * - **Leaf** (movie / episode): a hero poster beside its metadata (title,
 *   year / rating / runtime, genres, director, cast) and a {@see Renderer
 *   candy-shine} rendered, ↑/↓-scrollable synopsis, plus a Play entry-point:
 *   `p` direct-plays the item's signed `stream_url` via the sugar-reel player
 *   (a {@see \Phlix\Console\Msg\PlayRequestedMsg} the App turns into a
 *   PlayerScreen). An item with no signed source shows a brief notice instead.
 * - **Container** (series / season): a header plus a 2-D virtualized grid of the
 *   item's children (the seasons of a series, the episodes of a season), fetched
 *   by `parentId`. Enter opens the focused child's detail — so series → season →
 *   episode is just nested DetailScreens on the stack.
 *
 * The full item (leaf carries the signed `stream_url`) is fetched via
 * {@see MediaStore::item()}; posters render asynchronously so the screen appears
 * instantly with placeholders. Async child messages are tagged with the owning
 * `parentId` so a late result can't land on a *different* DetailScreen stacked
 * above. Stable collaborators are readonly; mutable view state is private and
 * copied via clone-mutate (the established screen idiom).
 */
final class DetailScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const HERO_WIDTH = 26;
    private const HERO_HEIGHT = 16;
    private const COL_GAP = 3;
    private const CARD_WIDTH = 14;
    private const POSTER_HEIGHT = 9;
    private const H_SPACING = 2;
    private const V_SPACING = 1;
    private const PAGE_LIMIT = 50;
    private const OVERSCAN = 1;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const PLAY_NOTICE = '▶  This title has no playable source.';
    private const HINT = '↑↓  scroll synopsis      p  play      C  cast      r  rate      F  favorite      w  watched      Esc  back';
    private const CONTAINER_HINT = '↑↓←→  move      ⏎  open      Esc  back';
    private const LOADING_HINT = 'Esc  back';

    private ?MediaItem $item = null;
    private bool $loaded = false;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    // Leaf mode.
    private ?string $heroAnsi = null;
    private bool $playNotice = false;
    private int $synopsisScroll = 0;
    private ?MediaRatings $ratings = null;
    private ?UserRatingPicker $ratingPicker = null;
    private ?bool $isFavorited = null;
    private ?bool $isWatched = null;
    /** @var ?MediaItem The item before the last optimistic favorite toggle (for revert). */
    private ?MediaItem $previousItem = null;
    // Container mode (null until a loaded item proves to be a series/season).
    private ?PosterGrid $childGrid = null;
    private ?MediaQuery $childQuery = null;
    private bool $childLoaded = false;
    /** @var array{0:int,1:int} the last child window requested (dedups fetches) */
    private array $childRequested = [0, -1];

    // Cached renderer for synopsis markdown rendering (performance optimization).
    private ?Renderer $synopsisRenderer = null;

    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly MediaStore $media,
        private readonly PosterLoader $posters,
        private readonly string $baseUrl,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchItem();
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return $this->onResize($msg->cols, $msg->rows);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof DetailLoadedMsg) {
            return $this->onLoaded($msg->item);
        }
        if ($msg instanceof DetailPosterLoadedMsg) {
            return [$this->withHero($msg->marker), null];
        }
        if ($msg instanceof DetailFailedMsg) {
            return [$this->withError($msg->reason), null];
        }
        if ($msg instanceof ChildrenLoadedMsg) {
            return $this->onChildren($msg->parentId, $msg->range);
        }
        if ($msg instanceof ChildPosterLoadedMsg) {
            return [$this->onChildPoster($msg->parentId, $msg->index, $msg->marker, $msg->imageId), null];
        }
        if ($msg instanceof ChildrenFailedMsg) {
            // Only a failure that blocked the first child load is surfaced.
            return [$msg->parentId === $this->id && !$this->childLoaded ? $this->withError($msg->reason) : $this, null];
        }
        if ($msg instanceof RatingsLoadedMsg) {
            return [$this->withRatings($msg->ratings), null];
        }
        if ($msg instanceof FavoriteToggledMsg) {
            // Optimistic update already applied; clear the revert snapshot.
            if ($this->previousItem !== null) {
                $next = clone $this;
                $next->previousItem = null;

                return [$next, null];
            }

            return [$this, null];
        }
        if ($msg instanceof FavoriteToggleFailedMsg) {
            // Revert the optimistic update and show a toast.
            $next = clone $this;
            if ($this->previousItem !== null) {
                $next->item = $this->previousItem;
                $next->isFavorited = $this->previousItem->isFavorite;
                $next->previousItem = null;
            }

            return [$next, Cmd::send(ShowToastMsg::error($msg->reason))];
        }
        if ($msg instanceof WatchedToggledMsg) {
            // Optimistic update already applied; clear the revert snapshot.
            if ($this->previousItem !== null) {
                $next = clone $this;
                $next->previousItem = null;

                return [$next, null];
            }

            return [$this, null];
        }
        if ($msg instanceof WatchedToggleFailedMsg) {
            // Revert the optimistic update and show a toast.
            $next = clone $this;
            if ($this->previousItem !== null) {
                $next->item = $this->previousItem;
                $next->isWatched = $this->previousItem->watched;
                $next->previousItem = null;
            }

            return [$next, Cmd::send(ShowToastMsg::error($msg->reason))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->error !== null) {
            return Chrome::frame($this->headerTitle(), "\n  {$this->error}", self::LOADING_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }
        if (!$this->loaded || $this->item === null) {
            return Chrome::frame($this->headerTitle(), "\n  Loading…", self::LOADING_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }
        if ($this->childGrid !== null) {
            return $this->containerView($this->item, $this->childGrid);
        }

        $hero = $this->heroAnsi ?? $this->heroPlaceholder();
        $column = $this->metadataColumn($this->item);
        $body = Layout::joinHorizontalWithSpacing(0.0, self::COL_GAP, $hero, $column);

        return Chrome::frame($this->headerTitle(), $body, self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // While the rating picker is open it captures every keystroke.
        if ($this->ratingPicker !== null) {
            return $this->handleRatingPickerKey($this->ratingPicker, $msg);
        }

        if ($msg->type === KeyType::Escape) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($this->childGrid !== null) {
            return $this->handleContainerKey($msg, $this->childGrid);
        }

        // Leaf: Play → direct-play the signed stream via the player; synopsis scroll.
        if ($msg->type === KeyType::Char && ($msg->rune === 'p' || $msg->rune === 'P')) {
            if ($this->item !== null && $this->item->streamUrl !== null) {
                return [$this, Cmd::send(new PlayRequestedMsg($this->item))];
            }
            // No signed stream on this item → nothing to direct-play.
            $next = clone $this;
            $next->playNotice = true;

            return [$next, null];
        }
        // Leaf: Cast → send the signed stream to a discovered device (mirrors `p`).
        if ($msg->type === KeyType::Char && $msg->rune === 'C') {
            if ($this->item !== null && $this->item->streamUrl !== null) {
                return [$this, Cmd::send(new CastRequestedMsg($this->item))];
            }

            return [$this, null];
        }
        // Leaf: r → open the user rating picker.
        if ($msg->type === KeyType::Char && ($msg->rune === 'r' || $msg->rune === 'R')) {
            return $this->openRatingPicker();
        }
        // Leaf: F → toggle favorite (optimistic update, revert on failure).
        if ($msg->type === KeyType::Char && ($msg->rune === 'f' || $msg->rune === 'F')) {
            return $this->toggleFavorite();
        }
        // Leaf: w → toggle watched (optimistic update, revert on failure).
        if ($msg->type === KeyType::Char && ($msg->rune === 'w' || $msg->rune === 'W')) {
            return $this->toggleWatched();
        }
        if ($msg->type === KeyType::Up) {
            return [$this->scrollSynopsis(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->scrollSynopsis(1), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleContainerKey(KeyMsg $msg, PosterGrid $grid): array
    {
        if ($msg->type === KeyType::Enter) {
            $card = $grid->cursorCard();

            return $card !== null
                ? [$this, Cmd::send(new OpenDetailMsg($card->id, $card->title))]
                : [$this, null];
        }

        $moved = match ($msg->type) {
            KeyType::Left => $grid->left(),
            KeyType::Right => $grid->right(),
            KeyType::Up => $grid->up(),
            KeyType::Down => $grid->down(),
            KeyType::PageUp => $grid->pageUp(),
            KeyType::PageDown => $grid->pageDown(),
            KeyType::Home => $grid->home(),
            KeyType::End => $grid->end(),
            default => $grid,
        };

        if ($moved === $grid) {
            return [$this, null];
        }

        return $this->afterChildGridChange($moved);
    }

    // ---- rating picker -------------------------------------------------

    /**
     * Open the user rating picker, pre-selecting the current user rating if set.
     *
     * @return array{self, ?\Closure}
     */
    private function openRatingPicker(): array
    {
        $currentUserRating = $this->ratings?->userRating()?->score;
        $next = clone $this;
        $next->ratingPicker = UserRatingPicker::open($currentUserRating !== null ? (int) $currentUserRating : null, $this->cols, $this->rows);

        return [$next, null];
    }

    /**
     * Drive the open rating picker: ←/→ move, 1-6 select, Enter confirm, Esc dismiss.
     *
     * @return array{self, ?\Closure}
     */
    private function handleRatingPickerKey(UserRatingPicker $picker, KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            $next = clone $this;
            $next->ratingPicker = null;

            return [$next, null];
        }
        if ($msg->type === KeyType::Left) {
            $next = clone $this;
            $next->ratingPicker = $picker->left();

            return [$next, null];
        }
        if ($msg->type === KeyType::Right) {
            $next = clone $this;
            $next->ratingPicker = $picker->right();

            return [$next, null];
        }
        // Number keys 1-6 directly select a position
        if ($msg->type === KeyType::Char && $msg->rune >= '1' && $msg->rune <= '6') {
            $index = (int) $msg->rune - 1; // 0-5
            $next = clone $this;
            // Use resizedTo to get a fresh picker with correct window dims, then navigate
            $next->ratingPicker = $picker->resizedTo($this->cols, $this->rows);
            // Navigate to the selected index (0 = clear, 1-5 = stars)
            for ($i = 0; $i < $index; $i++) {
                $next->ratingPicker = $next->ratingPicker->right();
            }

            return [$next, null];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->applyRatingSelection($picker);
        }

        return [$this, null];
    }

    /**
     * Apply the rating selection: optimistically update the model and send the API request.
     *
     * @return array{self, ?\Closure}
     */
    private function applyRatingSelection(UserRatingPicker $picker): array
    {
        $next = clone $this;
        $next->ratingPicker = null;

        $rating = $picker->selectedRating();
        $isClearing = $picker->isClearing();

        // Capture the previous ratings for potential revert
        $previousRatings = $this->ratings;

        // Optimistically update the model
        if ($isClearing) {
            $next->ratings = $this->ratings?->userRating() !== null
                ? $this->withoutUserRating($this->ratings)
                : $this->ratings;
        } else {
            $next->ratings = $this->withOptimisticUserRating($this->ratings, $rating ?? 0);
        }

        // Build the API promise
        $apiPromise = $isClearing
            ? $this->media->api()->deleteMediaRating($this->id)
            : $this->media->api()->setMediaRating($this->id, (float) $rating);

        return [
            $next,
            Cmd::promise(fn () => $apiPromise->then(
                static fn (bool $result): Msg => new RatingSetMsg($result),
                static fn (\Throwable $e): Msg => new RatingSetFailedMsg($e->getMessage()),
            )),
        ];
    }

    /**
     * Remove the user rating from the ratings object.
     */
    private function withoutUserRating(?MediaRatings $ratings): ?MediaRatings
    {
        if ($ratings === null) {
            return null;
        }

        $filtered = array_filter(
            $ratings->ratings,
            static fn (Rating $r): bool => !($r->source === 'user' && $r->type === 'user'),
        );

        return new MediaRatings($ratings->itemId, array_values($filtered), $ratings->aggregateScore);
    }

    /**
     * Add or update the user rating optimistically in the ratings object.
     */
    private function withOptimisticUserRating(?MediaRatings $ratings, int $score): MediaRatings
    {
        // Remove existing user rating if present
        $withoutUser = $this->withoutUserRating($ratings);

        // Create a new user rating
        $userRating = new Rating(
            id: 0,
            mediaItemId: $this->id,
            source: 'user',
            type: 'user',
            score: (float) $score,
            votes: null,
        );

        $newRatings = $withoutUser !== null ? $withoutUser->ratings : [];
        $newRatings[] = $userRating;

        return new MediaRatings(
            $this->id,
            $newRatings,
            $ratings?->aggregateScore,
        );
    }

    /**
     * Toggle the favorite state of the current item (optimistic update).
     *
     * @return array{self, ?\Closure}
     */
    private function toggleFavorite(): array
    {
        if ($this->item === null) {
            return [$this, null];
        }

        $next = clone $this;
        $wasFavorited = $this->isFavorited ?? false;
        $next->isFavorited = !$wasFavorited;
        // Capture for revert-on-failure: we store the current item so the
        // failure handler can restore it if the API call rejects.
        $next->previousItem = $this->item;

        $id = $this->id;
        $apiPromise = !$wasFavorited
            ? $this->media->api()->addFavorite($id)
            : $this->media->api()->removeFavorite($id);

        return [
            $next,
            Cmd::promise(fn () => $apiPromise->then(
                static fn (bool $_): Msg => new FavoriteToggledMsg($id, !$wasFavorited),
                static fn (\Throwable $e): Msg => new FavoriteToggleFailedMsg($e->getMessage()),
            )),
        ];
    }

    /**
     * Toggle the watched state of the current item (optimistic update).
     *
     * @return array{self, ?\Closure}
     */
    private function toggleWatched(): array
    {
        if ($this->item === null) {
            return [$this, null];
        }

        $next = clone $this;
        $wasWatched = $this->isWatched ?? false;
        $next->isWatched = !$wasWatched;
        // Capture for revert-on-failure: we store the current item so the
        // failure handler can restore it if the API call rejects.
        $next->previousItem = $this->item;

        $id = $this->id;
        $apiPromise = !$wasWatched
            ? $this->media->api()->markWatched($id)
            : $this->media->api()->markUnwatched($id);

        return [
            $next,
            Cmd::promise(fn () => $apiPromise->then(
                static fn (bool $_): Msg => new WatchedToggledMsg($id, !$wasWatched),
                static fn (\Throwable $e): Msg => new WatchedToggleFailedMsg($e->getMessage()),
            )),
        ];
    }

    private function scrollSynopsis(int $delta): self
    {
        $scroll = max(0, $this->synopsisScroll + $delta);
        if ($scroll === $this->synopsisScroll) {
            return $this;
        }
        $next = clone $this;
        $next->synopsisScroll = $scroll;

        return $next;
    }

    /** @return array{self, ?\Closure} */
    private function onResize(int $cols, int $rows): array
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        if ($this->childGrid !== null) {
            $grid = $this->childGrid->withViewport($this->containerViewportCols($cols), $this->containerViewportRows($rows));

            return $next->afterChildGridChange($grid);
        }

        return [$next, null];
    }

    // ---- data: the item ------------------------------------------------

    private function fetchItem(): \Closure
    {
        return Cmd::promise(fn () => $this->media->item($this->id)->then(
            static fn (MediaItem $item): Msg => new DetailLoadedMsg($item),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new DetailFailedMsg('Could not load this title.'),
        ));
    }

    /** @return array{self, ?\Closure} */
    private function onLoaded(MediaItem $item): array
    {
        $next = clone $this;
        $next->item = $item;
        $next->loaded = true;
        $next->isFavorited = $item->isFavorite;
        $next->isWatched = $item->watched;

        if ($item->isContainer()) {
            $grid = PosterGrid::new(self::CARD_WIDTH, self::POSTER_HEIGHT, self::H_SPACING, self::V_SPACING)
                ->withViewport($this->containerViewportCols($this->cols), $this->containerViewportRows($this->rows));
            $end = $this->windowEnd($grid);

            $next->childGrid = $grid;
            $next->childQuery = new MediaQuery(parentId: $this->id, limit: self::PAGE_LIMIT);
            $next->childRequested = [0, $end];

            return [$next, $next->fetchChildren(0, $end)];
        }

        // Leaf: load the hero poster (if any) and the ratings.
        $posterCmd = ($item->posterUrl !== null && $item->posterUrl !== '') ? $next->fetchHero($item->posterUrl) : null;
        // Ratings are only fetched when there's a poster (maintains original behavior).
        $ratingsCmd = $posterCmd !== null ? $next->fetchRatings() : null;

        // No poster means no ratings either (ratingsCmd is only set when posterCmd is set).
        if ($posterCmd === null) {
            return [$next, null];
        }

        // Poster present — batch with ratings if available.
        if ($ratingsCmd !== null) {
            return [$next, Cmd::batch($posterCmd, $ratingsCmd)];
        }

        return [$next, $posterCmd];
    }

    private function fetchHero(string $url): ?\Closure
    {
        // Resolve relative URLs against the server base URL; absolute/empty pass through.
        $url = $this->resolveUrl($url);
        if ($url === '') {
            return null;
        }
        // Defensive: validate URL has a valid http/https scheme before attempting load.
        // parse_url returns false for malformed URLs and null for URLs with no scheme.
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null || $scheme === false || !in_array($scheme, ['http', 'https'], true)) {
            // Skip relative URLs (no scheme), malformed URLs, or non-http(s) schemes silently.
            return null;
        }

        return Cmd::promise(fn () => $this->posters->load($url, self::HERO_WIDTH, self::HERO_HEIGHT)->then(
            function (\Phlix\Console\Media\PosterLoadResult $result): Msg {
                return new DetailPosterLoadedMsg($result->marker, $result->imageId);
            },
            static fn (\Throwable $e): ?Msg => null, // a broken poster keeps the placeholder
        ));
    }

    private function fetchRatings(): \Closure
    {
        return Cmd::promise(fn () => $this->media->ratings($this->id)->then(
            static fn (MediaRatings $ratings): Msg => new RatingsLoadedMsg($ratings),
            static fn (\Throwable $e): ?Msg => null, // ratings failure renders nothing
        ));
    }

    /**
     * Resolves a relative URL to an absolute URL using the configured baseUrl.
     * Handles empty strings and already-absolute http/https URLs as pass-through.
     * Relative URLs (e.g., /api/v1/artwork/...) are resolved against baseUrl.
     */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    // ---- data: the children grid (container mode) ----------------------

    private function fetchChildren(int $start, int $end): \Closure
    {
        $parentId = $this->id;
        $query = $this->childQuery ?? new MediaQuery(parentId: $parentId, limit: self::PAGE_LIMIT);

        return Cmd::promise(fn () => $this->media->ensureRange($query, $start, $end)->then(
            static fn (MediaRange $range): Msg => new ChildrenLoadedMsg($parentId, $range),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new ChildrenFailedMsg($parentId, 'Could not load this content.'),
        ));
    }

    /** @return array{self, ?\Closure} */
    private function onChildren(string $parentId, MediaRange $range): array
    {
        if ($parentId !== $this->id || $this->childGrid === null) {
            return [$this, null]; // a result for a different stacked DetailScreen
        }

        $grid = $this->childLoaded ? $this->childGrid->withTotal($range->total) : $this->childGrid->reset($range->total);
        $grid = $grid->withItems($this->childCards($range->items));

        $next = clone $this;
        $next->childGrid = $grid;
        $next->childLoaded = true;

        [$start, $end] = $grid->visibleRange(self::OVERSCAN);

        return [$next, $next->loadChildPostersIn($grid, $start, $end)];
    }

    private function onChildPoster(string $parentId, int $index, string $marker, ?int $imageId): self
    {
        if ($parentId !== $this->id || $this->childGrid === null) {
            return $this;
        }
        $card = $this->childGrid->item($index);
        if ($card === null) {
            return $this;
        }

        // Use withImage() for overlay modes (sixel/kitty/iterm2), withPoster() for inline modes
        if ($imageId !== null && !$this->posters->isInline()) {
            $bytes = $this->posters->imageLayer()[$imageId]->bytes ?? $marker;
            $newCard = $card->withImage($bytes, $imageId);
        } else {
            $newCard = $card->withPoster($marker);
        }

        $next = clone $this;
        $next->childGrid = $this->childGrid->withItem($index, $newCard);

        return $next;
    }

    /**
     * After the child grid's cursor/viewport moved: fetch the newly visible
     * window (if not already covered) and load posters for the cells on screen.
     *
     * @return array{self, ?\Closure}
     */
    private function afterChildGridChange(PosterGrid $grid): array
    {
        [$start, $end] = $grid->visibleRange(self::OVERSCAN);

        $cmds = [];
        $requested = $this->childRequested;
        if ($end >= $start && !($start >= $requested[0] && $end <= $requested[1])) {
            $cmds[] = $this->fetchChildren($start, $end);
            $requested = [$start, $end];
        }
        $posterCmd = $this->loadChildPostersIn($grid, $start, $end);
        if ($posterCmd !== null) {
            $cmds[] = $posterCmd;
        }

        $next = clone $this;
        $next->childGrid = $grid;
        $next->childRequested = $requested;

        return [$next, $cmds === [] ? null : Cmd::batch(...$cmds)];
    }

    /** Batch poster loads for the loaded, poster-less child cells in [start, end]. */
    private function loadChildPostersIn(PosterGrid $grid, int $start, int $end): ?\Closure
    {
        $parentId = $this->id;
        $cmds = [];
        for ($i = max(0, $start); $i <= $end; $i++) {
            $card = $grid->item($i);
            if ($card === null || $card->posterUrl === null || $card->posterUrl === '' || $card->hasPoster()) {
                continue;
            }
            // Resolve relative URLs against the server base URL; absolute/empty pass through.
            $url = $this->resolveUrl($card->posterUrl);
            if ($url === '') {
                continue;
            }
            // Defensive: validate URL has a valid http/https scheme before attempting load.
            // parse_url returns false for malformed URLs and null for URLs with no scheme.
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if ($scheme === null || $scheme === false || !in_array($scheme, ['http', 'https'], true)) {
                // Skip relative URLs (no scheme), malformed URLs, or non-http(s) schemes
                // silently - treat them the same as a missing poster.
                continue;
            }
            $index = $i;
            $cmds[] = Cmd::promise(fn () => $this->posters->load($url, self::CARD_WIDTH, self::POSTER_HEIGHT)->then(
                function (\Phlix\Console\Media\PosterLoadResult $result) use ($parentId, $index): Msg {
                    return new ChildPosterLoadedMsg($parentId, $index, $result->marker, $result->imageId);
                },
                static fn (\Throwable $e): ?Msg => null, // a broken poster keeps its skeleton
            ));
        }

        return $cmds === [] ? null : Cmd::batch(...$cmds);
    }

    /**
     * @param array<int, MediaItem> $items
     * @return array<int, \SugarCraft\Gallery\PosterCard>
     */
    private function childCards(array $items): array
    {
        $cards = [];
        foreach ($items as $index => $item) {
            $cards[$index] = PosterCardFactory::fromMediaItem($item);
        }

        return $cards;
    }

    private function windowEnd(PosterGrid $grid): int
    {
        return max(0, $grid->columns() * ($grid->visibleRows() + self::OVERSCAN) - 1);
    }

    // ---- rendering: container ------------------------------------------

    private function containerView(MediaItem $item, PosterGrid $grid): string
    {
        // The item name is already in the Chrome title bar, so the content header
        // is a single meta line (count · year · genres) — matching LibraryScreen's
        // one-line-plus-blank layout so the grid (incl. card titles) is not clipped.
        $parts = [$this->childLoaded ? $this->childKindLabel($grid->total()) : 'Loading…'];
        if ($item->year !== null) {
            $parts[] = (string) $item->year;
        }
        if ($item->genres !== []) {
            $parts[] = implode(', ', $item->genres);
        }
        $header = Width::truncate(implode('   ·   ', $parts), max(1, $this->cols - 4));

        $body = $header . "\n\n" . $grid->render(true);

        return Chrome::frame($this->headerTitle(), $body, self::CONTAINER_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    /** "3 seasons" for a series, "12 episodes" for a season, else "N items". */
    private function childKindLabel(int $count): string
    {
        $noun = match ($this->item?->type) {
            'series' => 'season',
            'season' => 'episode',
            default => 'item',
        };

        return $count . ' ' . $noun . ($count === 1 ? '' : 's');
    }

    private function containerViewportCols(int $cols): int
    {
        return max(self::CARD_WIDTH, $cols - 4);
    }

    private function containerViewportRows(int $rows): int
    {
        // The content panel fills the frame; window the children grid to that body
        // height less the meta line + blank (2), matching LibraryScreen so a full
        // grid of children renders without clipping.
        return max(self::POSTER_HEIGHT + 2, Chrome::bodyHeight($rows) - 2);
    }

    // ---- rendering: leaf -----------------------------------------------

    private function metadataColumn(MediaItem $item): string
    {
        $width = $this->columnWidth();
        $accent = Style::new()->bold();

        $lines = [$accent->render(Width::truncate($item->name, $width))];
        $lines[] = $this->metaLine($item);

        if ($item->genres !== []) {
            $lines[] = Width::truncate(implode(', ', $item->genres), $width);
        }
        $lines = $this->appendCrewLines($lines, $item, $width);
        $lines = $this->appendCastLines($lines, $item, $width);

        $header = $lines;
        $actions = $this->playNotice ? self::PLAY_NOTICE : '▶  p  Play        Esc  Back';

        // The synopsis fills whatever room remains, scrollable with ↑/↓.
        $reserved = count($header) + 3; // a blank above + a blank + the actions line below
        $synopsisRows = max(1, $this->bodyHeight() - $reserved);
        $synopsis = $this->synopsisWindow($item, $width, $synopsisRows);

        return implode("\n", [...$header, '', ...$synopsis, '', $actions]);
    }

    private function metaLine(MediaItem $item): string
    {
        $parts = [];
        if ($item->type === 'episode' && $item->seasonNumber !== null && $item->episodeNumber !== null) {
            $parts[] = sprintf('S%02dE%02d', $item->seasonNumber, $item->episodeNumber);
            if ($item->episodeTitle !== null && $item->episodeTitle !== '') {
                $parts[] = $item->episodeTitle;
            }
        } else {
            $parts[] = ucfirst($item->type);
        }
        if ($item->year !== null) {
            $parts[] = (string) $item->year;
        }
        if ($item->rating !== null && $item->rating !== '') {
            $parts[] = $item->rating;
        }
        $length = $this->lengthLabel($item);
        if ($length !== null) {
            $parts[] = $length;
        }

        // Append TMDB/IMDb rating badges if ratings have loaded.
        $ratings = $this->ratings;
        if ($ratings !== null) {
            foreach ($ratings->ratings as $rating) {
                if (in_array($rating->source, ['tmdb', 'imdb'], true) && $rating->type === 'average') {
                    $badge = new RatingBadge($rating->score);
                    $rendered = $badge->render();
                    if ($rendered !== '') {
                        $parts[] = $rendered;
                    }
                }
            }
        }

        // Append favorite marker if this item is favorited.
        if ($this->isFavorited === true) {
            $parts[] = '♥';
        }

        // Append watched marker if this item has been watched.
        if ($this->isWatched === true) {
            $parts[] = '✓';
        }

        return Width::truncate(implode('  ·  ', $parts), $this->columnWidth());
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function appendCrewLines(array $lines, MediaItem $item, int $width): array
    {
        $crew = $item->crew;
        if ($crew === null || $crew === []) {
            return $lines;
        }
        // Show director first if present.
        foreach ($crew as $member) {
            if ($member->job !== null && strcasecmp($member->job, 'director') === 0) {
                $lines[] = Width::truncate('Directed by ' . $member->name, $width);
                return $lines;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function appendCastLines(array $lines, MediaItem $item, int $width): array
    {
        $cast = $item->cast;
        if ($cast === null || $cast === []) {
            return $lines;
        }
        $lines[] = '';
        $accent = Style::new()->bold();
        $dim = Style::new()->faint();
        $lines[] = $accent->render('Cast');
        $maxShow = min(8, count($cast));
        for ($i = 0; $i < $maxShow; $i++) {
            $member = $cast[$i];
            $avatar = $this->avatarForCastMember($member, $dim);
            $role = $member->role !== null ? " as {$member->role}" : '';
            $lines[] = $avatar . ' ' . Width::truncate($member->name . $role, $width - 3);
        }
        if (count($cast) > $maxShow) {
            $remaining = count($cast) - $maxShow;
            $lines[] = $dim->render("  +{$remaining} more");
        }

        return $lines;
    }

    /**
     * Render a minimal initials-based avatar for a cast member using ANSI escape codes.
     */
    private function avatarForCastMember(CastMember $member, Style $style): string
    {
        static $colors = [
            'red' => '#ff0000',
            'green' => '#00ff00',
            'yellow' => '#ffff00',
            'blue' => '#0000ff',
            'magenta' => '#ff00ff',
            'cyan' => '#00ffff',
        ];
        $initials = $this->initials($member->name);
        $color = $colors[array_keys($colors)[crc32($member->name) % count($colors)]];
        return $style->bg($color)->render(" {$initials} ");
    }

    /**
     * Extract a two-character initials abbreviation from a name.
     */
    private function initials(string $name): string
    {
        $trimmed = trim($name);
        $parts = preg_split('/\s+/', $trimmed);
        if ($parts === false) {
            return strtoupper(substr($trimmed, 0, 2));
        }
        if (count($parts) === 1) {
            return strtoupper(substr($trimmed, 0, 2));
        }
        $first = substr($parts[count($parts) - 1], 0, 1);
        $second = substr($parts[0], 0, 1);

        return strtoupper($first . $second);
    }

    /** A human runtime — TMDB minutes if present, else the probed duration seconds. */
    private function lengthLabel(MediaItem $item): ?string
    {
        $minutes = $item->runtime ?? ($item->duration !== null ? intdiv($item->duration, 60) : null);
        if ($minutes === null || $minutes <= 0) {
            return null;
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h") : "{$m}m";
    }

    /**
     * Render the overview as markdown→ANSI (candy-shine), word-wrapped to the
     * column, and return the scrolled window of $rows lines.
     *
     * @return list<string>
     */
    private function synopsisWindow(MediaItem $item, int $width, int $rows): array
    {
        $overview = $item->overview;
        if ($overview === null || trim($overview) === '') {
            return ['No synopsis available.'];
        }

        try {
            $rendered = $this->getSynopsisRenderer($width)->render($overview);
        } catch (\Throwable $e) {
            // Fallback to plain text on render failure.
            return [Width::truncate($overview, $width)];
        }
        $all = explode("\n", rtrim($rendered, "\n"));

        $max = max(0, count($all) - $rows);
        $offset = min($this->synopsisScroll, $max);

        return array_slice($all, $offset, $rows);
    }

    /**
     * Get a cached Renderer instance configured for synopsis rendering.
     * Caching avoids repeated Renderer::ansi()->withWordWrap() instantiation.
     */
    private function getSynopsisRenderer(int $width): Renderer
    {
        if ($this->synopsisRenderer === null) {
            $this->synopsisRenderer = Renderer::ansi()->withWordWrap($width);
        }
        return $this->synopsisRenderer;
    }

    /** A dim placeholder block the exact size of the hero, shown until it loads. */
    private function heroPlaceholder(): string
    {
        $dim = Style::new()->faint();
        $row = $dim->render(str_repeat('░', self::HERO_WIDTH));

        return implode("\n", array_fill(0, self::HERO_HEIGHT, $row));
    }

    private function columnWidth(): int
    {
        return max(20, $this->cols - 4 - self::HERO_WIDTH - self::COL_GAP);
    }

    private function bodyHeight(): int
    {
        // The metadata column is sized to the content panel so its actions line
        // stays visible even on short terminals; the fixed-height hero poster
        // beside it simply clips at the bottom when the panel can't fit it.
        return Chrome::bodyHeight($this->rows);
    }

    private function headerTitle(): string
    {
        return $this->item->name ?? $this->name;
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withHero(string $ansi): self
    {
        $next = clone $this;
        $next->heroAnsi = $ansi;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;

        return $next;
    }

    private function withRatings(MediaRatings $ratings): self
    {
        $next = clone $this;
        $next->ratings = $ratings;

        return $next;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return $this->headerTitle();
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function item(): ?MediaItem
    {
        return $this->item;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function hasHero(): bool
    {
        return $this->heroAnsi !== null;
    }

    public function showsPlayNotice(): bool
    {
        return $this->playNotice;
    }

    /** Whether this detail rendered as a container (series/season) grid. */
    public function isContainer(): bool
    {
        return $this->childGrid !== null;
    }

    public function childGrid(): ?PosterGrid
    {
        return $this->childGrid;
    }
}

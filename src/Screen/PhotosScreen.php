<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\Media\PhotoCardFactory;
use Phlix\Console\Media\PosterLoadResult;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\GridPosterLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenPhotoAlbumMsg;
use Phlix\Console\Msg\PhotoRangeLoadedMsg;
use Phlix\Console\Msg\PhotosFailedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\PhotoRange;
use Phlix\Console\Store\PhotosStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Skeleton;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;

/**
 * A `photo`-type library's album-cover grid: a 2-D virtualized {@see PosterGrid}
 * over the library's date-grouped albums. ↑↓←→ move, PgUp/PgDn page, Home/End
 * jump, Enter opens an album's thumbnail grid, Esc returns.
 *
 * The album list is paged via {@see PhotosStore::ensureRange()}: only the
 * visible window (plus overscan) is fetched, so even a large library scrolls
 * smoothly. Each album's cover `thumbnail_url` is KNOWN UPFRONT, so the card is
 * built WITH it as `posterUrl` and the grid loads visible covers DIRECTLY (no
 * per-card detail fetch). An album with no cover keeps its placeholder. Cover
 * loading is strictly best-effort: any fetch/render failure simply leaves the
 * placeholder, never crashing the grid.
 *
 * Stable collaborators are readonly; mutable view state is private and copied via
 * clone-mutate (the established screen idiom).
 */
final class PhotosScreen implements Breadcrumbed, Loadable, Shimmering, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;
    use ShimmeringScreen;

    private const CARD_WIDTH = 14;
    private const POSTER_HEIGHT = 9;
    private const H_SPACING = 2;
    private const V_SPACING = 1;
    private const OVERSCAN = 1;
    private const PAGE_LIMIT = 100;
    private const HINT = '↑↓←→  move      ⏎  open      Esc  back';
    private const LOAD_MORE_FAILED = "Couldn't load more albums.";

    private PosterGrid $grid;
    /**
     * Sparse array of loaded albums, keyed by absolute index in the full result set.
     * @var array<int, PhotoAlbum>
     */
    private array $albums = [];
    private int $total = 0;
    private int $selected = 0;
    private bool $loaded = false;
    private ?string $error = null;
    private int $generation = 0;
    /** @var array{0:int,1:int} the last absolute window requested (dedups fetches) */
    private array $requestedRange;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly PhotosStore $store,
        private readonly PosterLoader $posters,
        private readonly string $baseUrl,
        private readonly string $libraryId,
        private readonly string $name,
        private int $cols = 80,
        private int $rows = 24,
    ) {
        $this->grid = PosterGrid::new(self::CARD_WIDTH, self::POSTER_HEIGHT, self::H_SPACING, self::V_SPACING)
            ->withViewport(self::viewportCols($cols), self::viewportRowsStatic($rows))
            ->reset(0);
        $this->requestedRange = [0, $this->initialWindowEnd()];
    }

    public function init(): \Closure
    {
        return $this->fetchRange(0, $this->initialWindowEnd());
    }

    private function initialWindowEnd(): int
    {
        return max(0, $this->viewportRows() - 1 + self::OVERSCAN);
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
        if ($msg instanceof PhotoRangeLoadedMsg) {
            return $this->onRange($msg->range, $msg->generation);
        }
        if ($msg instanceof GridPosterLoadedMsg) {
            return [$this->onPoster($msg->index, $msg->ansi), null];
        }
        if ($msg instanceof PhotosFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->error !== null) {
            return Chrome::frame($this->name, "\n  {$this->error}", self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        if (!$this->loaded) {
            // First-load: a full-body shimmer skeleton (animated by the App's
            // gated shimmer tick via $this->shimmerPhase) under a "Loading…" line,
            // until the album list arrives.
            $body = 'Loading…' . "\n\n" . Skeleton::bars($this->cols - 4, max(1, Chrome::bodyHeight($this->rows) - 2), $this->shimmerPhase(), $this->theme());

            return Chrome::frame($this->name, $body, self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        $total = $this->grid->total();
        if ($total === 0) {
            $header = 'No photo albums';
        } else {
            $header = $total . ' albums   ·   ' . ($this->grid->cursorIndex() + 1) . '/' . $total;
        }

        $body = $header . "\n\n" . $this->grid->render(true);

        return Chrome::frame($this->name, $body, self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Enter) {
            $album = $this->albums[$this->selected] ?? null;

            return $album !== null
                ? [$this, Cmd::send(new OpenPhotoAlbumMsg($album))]
                : [$this, null];
        }

        $grid = match ($msg->type) {
            KeyType::Left => $this->grid->left(),
            KeyType::Right => $this->grid->right(),
            KeyType::Up => $this->grid->up(),
            KeyType::Down => $this->grid->down(),
            KeyType::PageUp => $this->grid->pageUp(),
            KeyType::PageDown => $this->grid->pageDown(),
            KeyType::Home => $this->grid->home(),
            KeyType::End => $this->grid->end(),
            default => $this->grid,
        };

        if ($grid === $this->grid) {
            return [$this, null];
        }

        return $this->afterGridChange($grid);
    }

    /** @return array{self, ?\Closure} */
    private function onResize(int $cols, int $rows): array
    {
        $grid = $this->grid->withViewport(self::viewportCols($cols), self::viewportRowsStatic($rows));

        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;
        $next->grid = $grid;

        return $next->afterGridChange($grid);
    }

    // ---- data ----------------------------------------------------------

    /**
     * The paged album list landed: splice albums in at their absolute indices
     * and load the covers for the cells now on screen.
     *
     * @return array{self, ?\Closure}
     */
    private function onRange(PhotoRange $range, int $generation): array
    {
        if ($generation !== $this->generation) {
            return [$this, null]; // a superseded query's result
        }

        // Splice albums in at their absolute indices.
        $next = clone $this;
        $next->albums = $this->albums;
        foreach ($range->albums as $index => $album) {
            $next->albums[$index] = $album;
        }
        $next->total = $range->total;
        $next->loaded = true;
        $next->error = null;

        // Clamp selection to valid range.
        $count = $range->total > 0 ? $range->total : count($next->albums);
        if ($count > 0 && $next->selected >= $count) {
            $next->selected = $count - 1;
        }

        // Extend the requested window to cover what we now have loaded.
        $minIndex = $next->albums === [] ? 0 : min(array_keys($next->albums));
        $maxIndex = $next->albums === [] ? 0 : max(array_keys($next->albums));
        $next->requestedRange = [$minIndex, $maxIndex];

        // Rebuild cards for loaded albums and load covers.
        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            if (array_key_exists($i, $next->albums)) {
                $cards[$i] = PhotoCardFactory::fromAlbum($next->albums[$i]);
            } else {
                // For unloaded positions, create a blank card as placeholder.
                // PosterGrid renders empty poster area for cards with no posterUrl.
                $cards[$i] = new PosterCard('album-loading', '…');
            }
        }
        $next->grid = $this->grid->reset($count)->withItems($cards);

        [$start, $end] = $next->grid->visibleRange(self::OVERSCAN);

        return [$next, $next->loadCoversIn($next->grid, $start, $end)];
    }

    private function fetchRange(int $start, int $end): \Closure
    {
        $generation = $this->generation;

        return Cmd::promise(fn () => $this->store->ensureRange(
            $this->libraryId, $start, $end, self::PAGE_LIMIT,
        )->then(
            static fn (PhotoRange $range): Msg => new PhotoRangeLoadedMsg($range, $generation),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg('Your session expired. Please sign in again.')
                : new PhotosFailedMsg(self::LOAD_MORE_FAILED),
        ));
    }

    /**
     * After the grid's cursor/viewport moved: load covers for the cells now on
     * screen, and if the cursor moved beyond our loaded range, fetch more albums.
     *
     * @return array{self, ?\Closure}
     */
    private function afterGridChange(PosterGrid $grid): array
    {
        $next = clone $this;
        $next->grid = $grid;
        $next->selected = $grid->cursorIndex();

        $cursor = $grid->cursorIndex();
        $cmds = [];

        // If cursor moved beyond our loaded window, fetch more.
        if ($cursor < $next->requestedRange[0] || $cursor > $next->requestedRange[1]) {
            $count = $next->total > 0 ? $next->total : count($next->albums);
            $fetchBehind = $this->fetchBehind();
            $fetchAhead = $this->fetchAhead();
            $fetchStart = max(0, $cursor - $fetchBehind);
            $fetchEnd = min($count - 1, $cursor + $fetchAhead);
            $cmds[] = $this->fetchRange($fetchStart, $fetchEnd);
            $next->requestedRange = [$fetchStart, $fetchEnd];
        }

        $coverCmd = $next->loadCoversIn($grid, ...$grid->visibleRange(self::OVERSCAN));

        if ($cmds === [] && $coverCmd === null) {
            return [$next, null];
        }
        if ($coverCmd === null) {
            return [$next, Cmd::batch(...$cmds)];
        }
        if ($cmds === []) {
            return [$next, $coverCmd];
        }
        $cmds[] = $coverCmd;

        return [$next, Cmd::batch(...$cmds)];
    }

    private function fetchBehind(): int
    {
        return max(0, $this->viewportRows() - 1 + self::OVERSCAN);
    }

    private function fetchAhead(): int
    {
        return max(1, $this->viewportRows() - 1 + self::OVERSCAN);
    }

    private function onPoster(int $index, string $ansi): self
    {
        $card = $this->grid->item($index);
        if ($card === null) {
            return $this;
        }

        return $this->withGrid($this->grid->withItem($index, $card->withPoster($ansi)));
    }

    /**
     * Load covers for the cover-less cells in [start, end]: each card already
     * carries its signed thumbnail as `posterUrl`, so render it DIRECTLY (no
     * detail fetch) → {@see GridPosterLoadedMsg}. A card with a null `posterUrl`
     * (an album with no cover) is skipped — it keeps its placeholder. Any render
     * failure is swallowed so the cell keeps its placeholder, never crashing.
     */
    private function loadCoversIn(PosterGrid $grid, int $start, int $end): ?\Closure
    {
        $cmds = [];
        for ($i = max(0, $start); $i <= $end; $i++) {
            $card = $grid->item($i);
            if ($card === null || $card->hasPoster() || $card->posterUrl === null || $card->posterUrl === '') {
                continue;
            }
            // Resolve relative URLs against the server base URL BEFORE scheme validation;
            // absolute/empty pass through. A raw relative thumbnail (e.g. /cover.png) has
            // no scheme, so scheme-checking it first would drop it — resolve first.
            $url = $this->resolveUrl($card->posterUrl);
            if ($url === '') {
                continue;
            }
            // Defensive: validate URL has a valid http/https scheme before attempting load.
            // parse_url returns false for malformed URLs and null for URLs with no scheme.
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if ($scheme === null || $scheme === false || !in_array($scheme, ['http', 'https'], true)) {
                // Skip malformed URLs or non-http(s) schemes silently - treat them the
                // same as a missing poster.
                continue;
            }
            $cmds[] = $this->loadCover($i, $url);
        }

        return $cmds === [] ? null : Cmd::batch(...$cmds);
    }

    private function loadCover(int $index, string $thumbnailUrl): \Closure
    {
        return Cmd::promise(fn () => $this->posters->load($thumbnailUrl, self::CARD_WIDTH, self::POSTER_HEIGHT)->then(
            static fn (PosterLoadResult $result): Msg => new GridPosterLoadedMsg($index, $result->marker),
            static fn (\Throwable $e): ?Msg => null, // best-effort: a broken cover keeps its skeleton
        ));
    }

    /** Resolve a (possibly relative) URL against the server base; absolute/empty pass through. */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withGrid(PosterGrid $grid): self
    {
        $next = clone $this;
        $next->grid = $grid;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;

        return $next;
    }

    private function viewportRows(): int
    {
        return max(1, Chrome::bodyHeight($this->rows) - 2);
    }

    private static function viewportCols(int $cols): int
    {
        return max(self::CARD_WIDTH, $cols - 4);
    }

    private static function viewportRowsStatic(int $rows): int
    {
        // The content panel fills the frame; window the grid to that body height
        // less the count line + blank above it (2), so the bottom rows never clip.
        return max(self::POSTER_HEIGHT + 2, Chrome::bodyHeight($rows) - 2);
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return $this->name;
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function name(): string
    {
        return $this->name;
    }

    public function grid(): PosterGrid
    {
        return $this->grid;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /** True exactly while the screen shows its first-load shimmer body. */
    public function isLoading(): bool
    {
        return !$this->loaded && $this->error === null;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /** @return array<int, \Phlix\Console\Api\Dto\PhotoAlbum> */
    public function albums(): array
    {
        return $this->albums;
    }
}

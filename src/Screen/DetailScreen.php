<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Media\PosterCardFactory;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\ChildPosterLoadedMsg;
use Phlix\Console\Msg\ChildrenFailedMsg;
use Phlix\Console\Msg\ChildrenLoadedMsg;
use Phlix\Console\Msg\DetailFailedMsg;
use Phlix\Console\Msg\DetailLoadedMsg;
use Phlix\Console\Msg\DetailPosterLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\PlayRequestedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\MediaRange;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
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
final class DetailScreen implements Breadcrumbed
{
    use SubscriptionCapable;

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
    private const HINT = '↑↓  scroll synopsis      p  play      Esc  back';
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

    // Container mode (null until a loaded item proves to be a series/season).
    private ?PosterGrid $childGrid = null;
    private ?MediaQuery $childQuery = null;
    private bool $childLoaded = false;
    /** @var array{0:int,1:int} the last child window requested (dedups fetches) */
    private array $childRequested = [0, -1];

    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly MediaStore $media,
        private readonly PosterLoader $posters,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): ?\Closure
    {
        return $this->fetchItem();
    }

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
            return [$this->withHero($msg->ansi), null];
        }
        if ($msg instanceof DetailFailedMsg) {
            return [$this->withError($msg->reason), null];
        }
        if ($msg instanceof ChildrenLoadedMsg) {
            return $this->onChildren($msg->parentId, $msg->range);
        }
        if ($msg instanceof ChildPosterLoadedMsg) {
            return [$this->onChildPoster($msg->parentId, $msg->index, $msg->ansi), null];
        }
        if ($msg instanceof ChildrenFailedMsg) {
            // Only a failure that blocked the first child load is surfaced.
            return [$msg->parentId === $this->id && !$this->childLoaded ? $this->withError($msg->reason) : $this, null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->error !== null) {
            return Chrome::frame($this->headerTitle(), "\n  {$this->error}", self::LOADING_HINT, $this->cols, $this->rows, $this->crumbs);
        }
        if (!$this->loaded || $this->item === null) {
            return Chrome::frame($this->headerTitle(), "\n  Loading…", self::LOADING_HINT, $this->cols, $this->rows, $this->crumbs);
        }
        if ($this->childGrid !== null) {
            return $this->containerView($this->item, $this->childGrid);
        }

        $hero = $this->heroAnsi ?? $this->heroPlaceholder();
        $column = $this->metadataColumn($this->item);
        $body = Layout::joinHorizontalWithSpacing(0.0, self::COL_GAP, $hero, $column);

        return Chrome::frame($this->headerTitle(), $body, self::HINT, $this->cols, $this->rows, $this->crumbs);
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
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
        if ($msg->type === KeyType::Up) {
            return [$this->scrollSynopsis(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->scrollSynopsis(1), null];
        }

        return [$this, null];
    }

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

    private function onLoaded(MediaItem $item): array
    {
        $next = clone $this;
        $next->item = $item;
        $next->loaded = true;

        if ($item->isContainer()) {
            $grid = PosterGrid::new(self::CARD_WIDTH, self::POSTER_HEIGHT, self::H_SPACING, self::V_SPACING)
                ->withViewport($this->containerViewportCols($this->cols), $this->containerViewportRows($this->rows));
            $end = $this->windowEnd($grid);

            $next->childGrid = $grid;
            $next->childQuery = new MediaQuery(parentId: $this->id, limit: self::PAGE_LIMIT);
            $next->childRequested = [0, $end];

            return [$next, $next->fetchChildren(0, $end)];
        }

        // Leaf: load the hero poster (if any).
        $cmd = $item->posterUrl !== null ? $next->fetchHero($item->posterUrl) : null;

        return [$next, $cmd];
    }

    private function fetchHero(string $url): \Closure
    {
        return Cmd::promise(fn () => $this->posters->load($url, self::HERO_WIDTH, self::HERO_HEIGHT)->then(
            static fn (string $ansi): Msg => new DetailPosterLoadedMsg($ansi),
            static fn (\Throwable $e): ?Msg => null, // a broken poster keeps the placeholder
        ));
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

    private function onChildPoster(string $parentId, int $index, string $ansi): self
    {
        if ($parentId !== $this->id || $this->childGrid === null) {
            return $this;
        }
        $card = $this->childGrid->item($index);
        if ($card === null) {
            return $this;
        }

        $next = clone $this;
        $next->childGrid = $this->childGrid->withItem($index, $card->withPoster($ansi));

        return $next;
    }

    /**
     * After the child grid's cursor/viewport moved: fetch the newly visible
     * window (if not already covered) and load posters for the cells on screen.
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
            if ($card === null || $card->posterUrl === null || $card->hasPoster()) {
                continue;
            }
            $url = $card->posterUrl;
            $index = $i;
            $cmds[] = Cmd::promise(fn () => $this->posters->load($url, self::CARD_WIDTH, self::POSTER_HEIGHT)->then(
                static fn (string $ansi): Msg => new ChildPosterLoadedMsg($parentId, $index, $ansi),
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

        return Chrome::frame($this->headerTitle(), $body, self::CONTAINER_HINT, $this->cols, $this->rows, $this->crumbs);
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
        // Reserve the frame chrome (4) plus the meta line + blank (2), matching
        // LibraryScreen so a full grid of children renders without clipping.
        return max(self::POSTER_HEIGHT + 2, $rows - 6);
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
        if ($item->director !== null && $item->director !== '') {
            $lines[] = Width::truncate('Directed by ' . $item->director, $width);
        }
        if ($item->actors !== []) {
            $lines[] = Width::truncate('Cast: ' . implode(', ', $item->actors), $width);
        }

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

        return Width::truncate(implode('  ·  ', $parts), $this->columnWidth());
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

        $rendered = Renderer::ansi()->withWordWrap($width)->render($overview);
        $all = explode("\n", rtrim($rendered, "\n"));

        $max = max(0, count($all) - $rows);
        $offset = min($this->synopsisScroll, $max);

        return array_slice($all, $offset, $rows);
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
        return max(self::HERO_HEIGHT, $this->rows - 4);
    }

    private function headerTitle(): string
    {
        return $this->item?->name ?? $this->name;
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

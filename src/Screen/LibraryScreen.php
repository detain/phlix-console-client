<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Media\PosterCardFactory;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\GridPosterLoadedMsg;
use Phlix\Console\Msg\LibraryFailedMsg;
use Phlix\Console\Msg\MediaRangeLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
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
use SugarCraft\Gallery\PosterGrid;

/**
 * A single library's full grid: a 2-D virtualized {@see PosterGrid} over the
 * whole result set. It fetches only the visible window (plus a row of overscan)
 * via {@see MediaStore::ensureRange()} and splices items in at their absolute
 * index, so even a 5,000-item library scrolls smoothly. ↑↓←→ move, PgUp/PgDn
 * page, Home/End jump, Esc returns to the previous screen.
 */
final class LibraryScreen implements Model
{
    use SubscriptionCapable;

    private const CARD_WIDTH = 14;
    private const POSTER_HEIGHT = 9;
    private const H_SPACING = 2;
    private const V_SPACING = 1;
    private const PAGE_LIMIT = 50;
    private const OVERSCAN = 1;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const HINT = '↑↓←→  move      PgUp/PgDn  page      Home/End  ends      Esc  back      q  quit';

    private readonly MediaQuery $query;
    private readonly PosterGrid $grid;
    /** @var array{0:int,1:int} the last absolute window requested (dedups fetches) */
    private readonly array $requestedRange;

    public function __construct(
        private readonly string $libraryId,
        private readonly string $name,
        private readonly MediaStore $media,
        private readonly PosterLoader $posters,
        ?MediaQuery $query = null,
        ?PosterGrid $grid = null,
        private readonly bool $loaded = false,
        private readonly int $generation = 0,
        ?array $requestedRange = null,
        private readonly ?string $error = null,
        private readonly int $cols = 80,
        private readonly int $rows = 24,
    ) {
        // topLevel: a library grid shows movies + series, not seasons/episodes.
        $this->query = $query ?? new MediaQuery(libraryId: $libraryId, topLevel: true, limit: self::PAGE_LIMIT);
        $this->grid = $grid ?? PosterGrid::new(self::CARD_WIDTH, self::POSTER_HEIGHT, self::H_SPACING, self::V_SPACING)
            ->withViewport(self::viewportCols($cols), self::viewportRows($rows));
        // Seed the requested window to what init() fetches, so the first cursor
        // move inside it doesn't redundantly re-request the opening page.
        $this->requestedRange = $requestedRange ?? [0, $this->initialWindowEnd()];
    }

    public function init(): ?\Closure
    {
        // Before the total is known, fetch a viewport-sized window from offset 0.
        return $this->fetchRange(0, $this->initialWindowEnd());
    }

    private function initialWindowEnd(): int
    {
        return max(0, $this->grid->columns() * ($this->grid->visibleRows() + self::OVERSCAN) - 1);
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return $this->onResize($msg->cols, $msg->rows);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof MediaRangeLoadedMsg) {
            return $this->onRange($msg->range, $msg->generation);
        }
        if ($msg instanceof GridPosterLoadedMsg) {
            return [$this->onPoster($msg->index, $msg->ansi), null];
        }
        if ($msg instanceof LibraryFailedMsg) {
            // Only surface a failure that blocked the first load; ignore a
            // transient scroll-time error once the grid is populated.
            return [$this->loaded ? $this : $this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->error !== null) {
            return Chrome::frame($this->name, "\n  {$this->error}", self::HINT, $this->cols, $this->rows);
        }

        $total = $this->grid->total();
        if (!$this->loaded) {
            $header = 'Loading…';
        } elseif ($total === 0) {
            $header = 'No items';
        } else {
            $header = $total . ' items   ·   ' . ($this->grid->cursorIndex() + 1) . '/' . $total;
        }

        $body = $header . "\n\n" . $this->grid->render(true);

        return Chrome::frame($this->name, $body, self::HINT, $this->cols, $this->rows);
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
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

    private function onResize(int $cols, int $rows): array
    {
        $grid = $this->grid->withViewport(self::viewportCols($cols), self::viewportRows($rows));
        $next = new self(
            $this->libraryId, $this->name, $this->media, $this->posters,
            $this->query, $grid, $this->loaded, $this->generation, $this->requestedRange, $this->error, $cols, $rows,
        );

        return $next->afterGridChange($grid);
    }

    // ---- data ----------------------------------------------------------

    /**
     * After the grid's cursor/viewport moved: fetch the new visible window (if
     * not already covered) and load posters for the cells now on screen.
     */
    private function afterGridChange(PosterGrid $grid): array
    {
        [$start, $end] = $grid->visibleRange(self::OVERSCAN);

        $cmds = [];
        $requested = $this->requestedRange;
        if ($end >= $start && !($start >= $requested[0] && $end <= $requested[1])) {
            $cmds[] = $this->fetchRange($start, $end);
            $requested = [$start, $end];
        }
        $posterCmd = $this->loadPostersIn($grid, $start, $end);
        if ($posterCmd !== null) {
            $cmds[] = $posterCmd;
        }

        $next = new self(
            $this->libraryId, $this->name, $this->media, $this->posters,
            $this->query, $grid, $this->loaded, $this->generation, $requested, $this->error, $this->cols, $this->rows,
        );

        return [$next, $cmds === [] ? null : Cmd::batch(...$cmds)];
    }

    private function onRange(MediaRange $range, int $generation): array
    {
        if ($generation !== $this->generation) {
            return [$this, null]; // a superseded query's result
        }

        $grid = $this->loaded ? $this->grid->withTotal($range->total) : $this->grid->reset($range->total);
        $grid = $grid->withItems($this->cards($range->items));

        $next = new self(
            $this->libraryId, $this->name, $this->media, $this->posters,
            $this->query, $grid, true, $this->generation, $this->requestedRange, $this->error, $this->cols, $this->rows,
        );

        [$start, $end] = $grid->visibleRange(self::OVERSCAN);

        return [$next, $next->loadPostersIn($grid, $start, $end)];
    }

    private function onPoster(int $index, string $ansi): self
    {
        $card = $this->grid->item($index);
        if ($card === null) {
            return $this;
        }

        return $this->withGrid($this->grid->withItem($index, $card->withPoster($ansi)));
    }

    private function fetchRange(int $start, int $end): \Closure
    {
        $generation = $this->generation;

        return Cmd::promise(fn () => $this->media->ensureRange($this->query, $start, $end)->then(
            static fn (MediaRange $range): Msg => new MediaRangeLoadedMsg($range, $generation),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new LibraryFailedMsg('Could not load this library.'),
        ));
    }

    /** Batch poster loads for the loaded, poster-less cells in [start, end]. */
    private function loadPostersIn(PosterGrid $grid, int $start, int $end): ?\Closure
    {
        $cmds = [];
        for ($i = max(0, $start); $i <= $end; $i++) {
            $card = $grid->item($i);
            if ($card === null || $card->posterUrl === null || $card->hasPoster()) {
                continue;
            }
            $url = $card->posterUrl;
            $index = $i;
            $cmds[] = Cmd::promise(fn () => $this->posters->load($url, self::CARD_WIDTH, self::POSTER_HEIGHT)->then(
                static fn (string $ansi): Msg => new GridPosterLoadedMsg($index, $ansi),
                static fn (\Throwable $e): ?Msg => null, // a broken poster keeps its skeleton
            ));
        }

        return $cmds === [] ? null : Cmd::batch(...$cmds);
    }

    /**
     * @param array<int, \Phlix\Console\Api\Dto\MediaItem> $items
     * @return array<int, \SugarCraft\Gallery\PosterCard>
     */
    private function cards(array $items): array
    {
        $cards = [];
        foreach ($items as $index => $item) {
            $cards[$index] = PosterCardFactory::fromMediaItem($item);
        }

        return $cards;
    }

    // ---- immutable copies ----------------------------------------------

    private function withGrid(PosterGrid $grid): self
    {
        return new self(
            $this->libraryId, $this->name, $this->media, $this->posters,
            $this->query, $grid, $this->loaded, $this->generation, $this->requestedRange, $this->error, $this->cols, $this->rows,
        );
    }

    private function withError(string $error): self
    {
        return new self(
            $this->libraryId, $this->name, $this->media, $this->posters,
            $this->query, $this->grid, $this->loaded, $this->generation, $this->requestedRange, $error, $this->cols, $this->rows,
        );
    }

    private static function viewportCols(int $cols): int
    {
        return max(self::CARD_WIDTH, $cols - 4);
    }

    private static function viewportRows(int $rows): int
    {
        // Reserve the frame chrome (header/status/borders) + the in-content
        // count line + a spacer.
        return max(self::POSTER_HEIGHT + 2, $rows - 6);
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

    public function error(): ?string
    {
        return $this->error;
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\LetterIndex;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Media\PosterCardFactory;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\GridPosterLoadedMsg;
use Phlix\Console\Msg\LetterIndexLoadedMsg;
use Phlix\Console\Msg\LibraryFailedMsg;
use Phlix\Console\Msg\MediaRangeLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\MediaRange;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\LetterRail;
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
 * page, Home/End jump, a letter jumps A–Z, Esc returns to the previous screen.
 *
 * Stable collaborators (library id/name + stores) are readonly constructor
 * properties; the mutable view state is private and copied via clone-mutate
 * (the candy-core / sugar-gallery pattern) so the screen stays immutable without
 * a giant positional constructor.
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
    private const HINT = '↑↓←→  move      PgUp/PgDn  page      A–Z  jump      Esc  back';

    private MediaQuery $query;
    private PosterGrid $grid;
    private bool $loaded = false;
    private int $generation = 0;
    /** @var array{0:int,1:int} the last absolute window requested (dedups fetches) */
    private array $requestedRange;
    private ?LetterIndex $letterIndex = null;
    private ?string $error = null;

    public function __construct(
        private readonly string $libraryId,
        private readonly string $name,
        private readonly MediaStore $media,
        private readonly PosterLoader $posters,
        private int $cols = 80,
        private int $rows = 24,
    ) {
        // topLevel: a library grid shows movies + series, not seasons/episodes.
        $this->query = new MediaQuery(libraryId: $libraryId, topLevel: true, limit: self::PAGE_LIMIT);
        $this->grid = PosterGrid::new(self::CARD_WIDTH, self::POSTER_HEIGHT, self::H_SPACING, self::V_SPACING)
            ->withViewport(self::viewportCols($cols), self::viewportRows($rows));
        // Seed the requested window to what init() fetches, so the first cursor
        // move inside it doesn't redundantly re-request the opening page.
        $this->requestedRange = [0, $this->initialWindowEnd()];
    }

    public function init(): ?\Closure
    {
        // Before the total is known, fetch a viewport-sized window from offset 0,
        // plus the A–Z index (only valid for the default name-ascending sort).
        $cmds = [$this->fetchRange(0, $this->initialWindowEnd())];
        if ($this->isNameAscending()) {
            $cmds[] = $this->fetchLetterIndex();
        }

        return Cmd::batch(...$cmds);
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
        if ($msg instanceof LetterIndexLoadedMsg) {
            return [$msg->generation === $this->generation ? $this->withLetterIndex($msg->index) : $this, null];
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

        $rail = '';
        if ($this->letterIndex !== null && $this->isNameAscending()) {
            $current = $this->letterIndex->letterAt($this->grid->cursorIndex());
            $rail = (new LetterRail($this->letterIndex, $current))->render();
        }

        $body = $rail !== ''
            ? $header . "\n" . $rail . "\n" . $this->grid->render(true)
            : $header . "\n\n" . $this->grid->render(true);

        return Chrome::frame($this->name, $body, self::HINT, $this->cols, $this->rows);
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        // Type-to-jump: a letter (or # / digit) scrolls the grid to that bucket.
        // (Quit is Ctrl-C globally, or Esc back to Browse — so letters are free.)
        if ($msg->type === KeyType::Char) {
            return $this->jumpToLetter($msg->rune);
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

    /** Jump the grid to the bucket for a typed letter (`#`/digits → non-alpha). */
    private function jumpToLetter(string $rune): array
    {
        if ($this->letterIndex === null || !$this->isNameAscending()) {
            return [$this, null];
        }

        $letter = ctype_alpha($rune)
            ? strtoupper($rune)
            : ((ctype_digit($rune) || $rune === '#') ? '#' : null);

        if ($letter === null || !in_array($letter, $this->letterIndex->enabledLetters(), true)) {
            return [$this, null];
        }

        $offset = $this->letterIndex->offsetFor($letter);
        $grid = $offset !== null ? $this->grid->moveTo($offset) : $this->grid;
        if ($grid === $this->grid) {
            return [$this, null];
        }

        return $this->afterGridChange($grid);
    }

    private function isNameAscending(): bool
    {
        return in_array($this->query->sort, [null, 'name'], true)
            && in_array($this->query->order, [null, 'asc'], true);
    }

    private function onResize(int $cols, int $rows): array
    {
        $grid = $this->grid->withViewport(self::viewportCols($cols), self::viewportRows($rows));

        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;
        $next->grid = $grid;

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

        $next = clone $this;
        $next->grid = $grid;
        $next->requestedRange = $requested;

        return [$next, $cmds === [] ? null : Cmd::batch(...$cmds)];
    }

    private function onRange(MediaRange $range, int $generation): array
    {
        if ($generation !== $this->generation) {
            return [$this, null]; // a superseded query's result
        }

        $grid = $this->loaded ? $this->grid->withTotal($range->total) : $this->grid->reset($range->total);
        $grid = $grid->withItems($this->cards($range->items));

        $next = clone $this;
        $next->grid = $grid;
        $next->loaded = true;

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

    private function fetchLetterIndex(): \Closure
    {
        $generation = $this->generation;

        return Cmd::promise(fn () => $this->media->letterIndex($this->query)->then(
            static fn (LetterIndex $index): ?Msg => new LetterIndexLoadedMsg($index, $generation),
            static fn (\Throwable $e): ?Msg => null, // the A–Z rail is optional; fail quietly
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

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withGrid(PosterGrid $grid): self
    {
        $next = clone $this;
        $next->grid = $grid;

        return $next;
    }

    private function withLetterIndex(LetterIndex $index): self
    {
        $next = clone $this;
        $next->letterIndex = $index;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;

        return $next;
    }

    private static function viewportCols(int $cols): int
    {
        return max(self::CARD_WIDTH, $cols - 4);
    }

    private static function viewportRows(int $rows): int
    {
        // Reserve the frame chrome (header + status + content border = 4) and the
        // two in-content lines above the grid (the count line + the A–Z rail, or
        // a blank spacer when the rail is hidden).
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

    public function letterIndex(): ?LetterIndex
    {
        return $this->letterIndex;
    }
}

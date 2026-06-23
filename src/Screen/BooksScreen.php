<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Book;
use Phlix\Console\Media\BookCardFactory;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\BooksFailedMsg;
use Phlix\Console\Msg\BooksRangeLoadedMsg;
use Phlix\Console\Msg\GridPosterLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenBookMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Store\BooksStore;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;

/**
 * A `book`-type library's grid: a 2-D virtualized {@see PosterGrid} over the
 * library's whole book count. It fetches only the visible window (plus a row of
 * overscan) via {@see BooksStore::ensureRange()} and splices books in at their
 * absolute index, so a large library scrolls smoothly. ↑↓←→ move, PgUp/PgDn
 * page, Home/End jump, Enter opens a book's detail, Esc returns.
 *
 * The one real difference from {@see LibraryScreen}: a book carries no cover URL
 * in the list shape, so each card's cover is resolved LAZILY per cell — fetch
 * the book's detail (which mints a signed `cover_url`), then render that to ANSI.
 * Cover loading is strictly best-effort: a null cover or any fetch/render
 * failure simply leaves the placeholder, never crashing the grid.
 *
 * The grid total is the library's item count, PASSED IN at construction (the
 * `/books` endpoint sends none) — any trailing gap shows as skeleton cells.
 * Stable collaborators are readonly; mutable view state is private and copied via
 * clone-mutate (the established screen idiom). Unlike LibraryScreen there is no
 * A–Z rail (books have no letter index) and no filter bar.
 */
final class BooksScreen implements Breadcrumbed
{
    use SubscriptionCapable;

    private const CARD_WIDTH = 14;
    private const POSTER_HEIGHT = 9;
    private const H_SPACING = 2;
    private const V_SPACING = 1;
    private const PAGE_LIMIT = 50;
    private const OVERSCAN = 1;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_MORE_FAILED = "Couldn't load more books.";
    private const HINT = '↑↓←→  move      ⏎  open      Esc  back';

    private PosterGrid $grid;
    private bool $loaded = false;
    private int $generation = 0;
    /** @var array{0:int,1:int} the last absolute window requested (dedups fetches) */
    private array $requestedRange;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly BooksStore $books,
        private readonly PosterLoader $posters,
        private readonly string $baseUrl,
        private readonly string $libraryId,
        private readonly string $name,
        private readonly int $total,
        private int $cols = 80,
        private int $rows = 24,
    ) {
        $this->grid = PosterGrid::new(self::CARD_WIDTH, self::POSTER_HEIGHT, self::H_SPACING, self::V_SPACING)
            ->withViewport(self::viewportCols($cols), self::viewportRows($cols, $rows))
            ->reset(max(0, $total));
        $this->requestedRange = [0, $this->initialWindowEnd()];
    }

    public function init(): ?\Closure
    {
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
        if ($msg instanceof BooksRangeLoadedMsg) {
            return $this->onRange($msg->books, $msg->generation);
        }
        if ($msg instanceof GridPosterLoadedMsg) {
            return [$this->onPoster($msg->index, $msg->ansi), null];
        }
        if ($msg instanceof BooksFailedMsg) {
            // A failure that blocked the first load replaces the body; a transient
            // scroll-time error keeps the populated grid and surfaces a toast.
            return $this->loaded
                ? [$this, Cmd::send(ShowToastMsg::error(self::LOAD_MORE_FAILED))]
                : [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->error !== null) {
            return Chrome::frame($this->name, "\n  {$this->error}", self::HINT, $this->cols, $this->rows, $this->crumbs);
        }

        $total = $this->grid->total();
        if (!$this->loaded) {
            $header = 'Loading…';
        } elseif ($total === 0) {
            $header = 'No books';
        } else {
            $header = $total . ' books   ·   ' . ($this->grid->cursorIndex() + 1) . '/' . $total;
        }

        $body = $header . "\n\n" . $this->grid->render(true);

        return Chrome::frame($this->name, $body, self::HINT, $this->cols, $this->rows, $this->crumbs);
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Enter) {
            $card = $this->grid->cursorCard();

            return $card !== null
                ? [$this, Cmd::send(new OpenBookMsg($card->id, $card->title))]
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

    private function onResize(int $cols, int $rows): array
    {
        $grid = $this->grid->withViewport(self::viewportCols($cols), self::viewportRows($cols, $rows));

        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;
        $next->grid = $grid;

        return $next->afterGridChange($grid);
    }

    // ---- data ----------------------------------------------------------

    /**
     * After the grid's cursor/viewport moved: fetch the new visible window (if
     * not already covered) and load covers for the cells now on screen.
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
        $coverCmd = $this->loadCoversIn($grid, $start, $end);
        if ($coverCmd !== null) {
            $cmds[] = $coverCmd;
        }

        $next = clone $this;
        $next->grid = $grid;
        $next->requestedRange = $requested;

        return [$next, $cmds === [] ? null : Cmd::batch(...$cmds)];
    }

    /**
     * @param array<int, Book> $books absolute index → book
     */
    private function onRange(array $books, int $generation): array
    {
        if ($generation !== $this->generation) {
            return [$this, null]; // a superseded window's result
        }

        $grid = $this->grid->withItems($this->cards($books));

        $next = clone $this;
        $next->grid = $grid;
        $next->loaded = true;

        [$start, $end] = $grid->visibleRange(self::OVERSCAN);

        return [$next, $next->loadCoversIn($grid, $start, $end)];
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

        return Cmd::promise(fn () => $this->books->ensureRange($this->libraryId, $this->total, $start, $end, self::PAGE_LIMIT)->then(
            static fn (array $books): Msg => new BooksRangeLoadedMsg($books, $generation),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new BooksFailedMsg('Could not load this library.'),
        ));
    }

    /**
     * Lazily load covers for the loaded, cover-less cells in [start, end]: each
     * needs the book's DETAIL first (the list shape has no cover URL), so chain
     * `book(id)` → resolve `coverUrl` → render → {@see GridPosterLoadedMsg}. A
     * null cover or any failure is swallowed so the cell keeps its placeholder.
     */
    private function loadCoversIn(PosterGrid $grid, int $start, int $end): ?\Closure
    {
        $cmds = [];
        for ($i = max(0, $start); $i <= $end; $i++) {
            $card = $grid->item($i);
            if ($card === null || $card->hasPoster()) {
                continue;
            }
            $cmds[] = $this->loadCover($i, $card->id);
        }

        return $cmds === [] ? null : Cmd::batch(...$cmds);
    }

    private function loadCover(int $index, string $bookId): \Closure
    {
        return Cmd::promise(fn () => $this->books->book($bookId)->then(
            function (Book $book): mixed {
                if ($book->coverUrl === null) {
                    return null; // no cover → keep the placeholder
                }

                return $this->posters->load($this->resolveUrl($book->coverUrl), self::CARD_WIDTH, self::POSTER_HEIGHT);
            },
        )->then(
            static fn (?string $ansi): ?Msg => $ansi !== null ? new GridPosterLoadedMsg($index, $ansi) : null,
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

    /**
     * @param array<int, Book> $books
     * @return array<int, PosterCard>
     */
    private function cards(array $books): array
    {
        $cards = [];
        foreach ($books as $index => $book) {
            $cards[$index] = BookCardFactory::fromBook($book);
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

    private static function viewportRows(int $cols, int $rows): int
    {
        // Window to the frame's REAL content height (a fraction of $rows — the
        // bordered region only gets part of the height), less the count line +
        // blank above the grid (2), so the bottom rows are never clipped.
        return max(self::POSTER_HEIGHT + 2, Chrome::contentHeight($cols, $rows) - 2);
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

    public function error(): ?string
    {
        return $this->error;
    }
}

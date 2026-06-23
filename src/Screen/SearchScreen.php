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
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\SearchDebouncedMsg;
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
use SugarCraft\Fuzzy\Highlighter;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Gallery\PosterGrid;
use SugarCraft\Sprinkles\Style;

/**
 * Global search: a persistent debounced query box over a 2-D virtualized
 * {@see PosterGrid} of `/media?search=` results. Typing debounces into a single
 * query (stale keystrokes are dropped via a sequence guard), results stream into
 * the grid window like the {@see LibraryScreen}, ↑↓←→ move, Enter opens a result,
 * Esc returns. Reachable from the global `/` key and the command palette.
 *
 * Implements {@see CapturesSlash} so `/` types into the box instead of
 * re-opening search.
 */
final class SearchScreen implements Breadcrumbed, CapturesSlash
{
    use SubscriptionCapable;

    private const CARD_WIDTH = 14;
    private const POSTER_HEIGHT = 9;
    private const H_SPACING = 2;
    private const V_SPACING = 1;
    private const PAGE_LIMIT = 50;
    private const OVERSCAN = 1;
    private const SEARCH_DEBOUNCE = 0.3;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const HINT = 'type to search      ↑↓←→  move      ⏎  open      Esc  back';

    private string $searchText = '';
    private MediaQuery $query;
    private PosterGrid $grid;
    private bool $loaded = false;
    private bool $hasSearched = false;
    private int $generation = 0;
    private int $searchSeq = 0;
    /** @var array{0:int,1:int} the last absolute window requested (dedups fetches) */
    private array $requestedRange = [0, 0];
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly MediaStore $media,
        private readonly PosterLoader $posters,
        private int $cols = 80,
        private int $rows = 24,
    ) {
        $this->query = new MediaQuery(topLevel: true, limit: self::PAGE_LIMIT);
        $this->grid = PosterGrid::new(self::CARD_WIDTH, self::POSTER_HEIGHT, self::H_SPACING, self::V_SPACING)
            ->withViewport(self::viewportCols($cols), self::viewportRows($rows));
    }

    public function init(): ?\Closure
    {
        return null; // nothing to fetch until the user types
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return $this->onResize($msg->cols, $msg->rows);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof SearchDebouncedMsg) {
            return $msg->seq === $this->searchSeq ? $this->applySearch() : [$this, null];
        }
        if ($msg instanceof MediaRangeLoadedMsg) {
            return $this->onRange($msg->range, $msg->generation);
        }
        if ($msg instanceof GridPosterLoadedMsg) {
            return [$this->onPoster($msg->index, $msg->ansi), null];
        }
        if ($msg instanceof LibraryFailedMsg) {
            return [$this->loaded ? $this : $this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        $inputLine = 'Search: ' . ($this->searchText === '' ? '' : $this->searchText) . '▏';

        if ($this->error !== null) {
            $body = $inputLine . "\n\n  {$this->error}";

            return Chrome::frame('Search', $body, self::HINT, $this->cols, $this->rows, $this->crumbs);
        }

        if (!$this->hasSearched) {
            $body = $inputLine . "\n\n  Type to search across your libraries.";

            return Chrome::frame('Search', $body, self::HINT, $this->cols, $this->rows, $this->crumbs);
        }

        $total = $this->grid->total();
        if (!$this->loaded) {
            $count = 'Searching…';
        } elseif ($total === 0) {
            $count = 'No results for "' . $this->searchText . '"';
        } else {
            $count = $total . ' result' . ($total === 1 ? '' : 's') . '   ·   ' . ($this->grid->cursorIndex() + 1) . '/' . $total;
        }

        $body = $inputLine . "\n" . $count . "\n" . $this->grid->render(true);

        return Chrome::frame('Search', $body, self::HINT, $this->cols, $this->rows, $this->crumbs);
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
                ? [$this, Cmd::send(new OpenDetailMsg($card->id, $card->title))]
                : [$this, null];
        }
        if ($msg->type === KeyType::Backspace) {
            return $this->searchText === '' ? [$this, null] : $this->editSearch(mb_substr($this->searchText, 0, -1));
        }
        if ($msg->type === KeyType::Space) {
            return $this->editSearch($this->searchText . ' ');
        }
        if ($msg->type === KeyType::Char && $msg->rune !== '') {
            return $this->editSearch($this->searchText . $msg->rune);
        }

        // Arrow / paging keys move the result grid.
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

    /** Edit the query text and (re)arm the debounce timer. */
    private function editSearch(string $text): array
    {
        $next = clone $this;
        $next->searchText = $text;
        $next->searchSeq = $this->searchSeq + 1;
        $seq = $next->searchSeq;

        return [$next, Cmd::tick(self::SEARCH_DEBOUNCE, static fn (): Msg => new SearchDebouncedMsg($seq))];
    }

    /** Apply the debounced query: rebuild it, reset the grid, refetch. */
    private function applySearch(): array
    {
        $next = clone $this;
        $next->query = new MediaQuery(
            search: $this->searchText !== '' ? $this->searchText : null,
            topLevel: true,
            limit: self::PAGE_LIMIT,
        );
        $next->generation = $this->generation + 1;
        $next->grid = $this->grid->reset(0);
        $next->loaded = false;
        $next->error = null;

        if ($this->searchText === '') {
            // Cleared the box → back to the prompt, no fetch.
            $next->hasSearched = false;
            $next->requestedRange = [0, 0];

            return [$next, null];
        }

        $next->hasSearched = true;
        $next->requestedRange = [0, $next->initialWindowEnd()];

        return [$next, $next->fetchRange(0, $next->initialWindowEnd())];
    }

    private function initialWindowEnd(): int
    {
        return max(0, $this->grid->columns() * ($this->grid->visibleRows() + self::OVERSCAN) - 1);
    }

    // ---- data ----------------------------------------------------------

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

        $next = clone $this;
        $next->grid = $this->grid->withItem($index, $card->withPoster($ansi));

        return $next;
    }

    private function fetchRange(int $start, int $end): \Closure
    {
        $generation = $this->generation;

        return Cmd::promise(fn () => $this->media->ensureRange($this->query, $start, $end)->then(
            static fn (MediaRange $range): Msg => new MediaRangeLoadedMsg($range, $generation),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new LibraryFailedMsg('Could not run your search.'),
        ));
    }

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
                static fn (\Throwable $e): ?Msg => null,
            ));
        }

        return $cmds === [] ? null : Cmd::batch(...$cmds);
    }

    /**
     * Build the grid cards for a page of results, fuzzy-highlighting the matched
     * characters of each title against the query that produced them (so the user
     * sees *why* a result matched). The plain title is kept for identity/sort.
     *
     * @param array<int, \Phlix\Console\Api\Dto\MediaItem> $items
     * @return array<int, \SugarCraft\Gallery\PosterCard>
     */
    private function cards(array $items): array
    {
        $query = (string) $this->query->search;
        $matcher = new SmithWatermanMatcher();
        $highlighter = new Highlighter();

        $cards = [];
        foreach ($items as $index => $item) {
            $card = PosterCardFactory::fromMediaItem($item);

            $match = $matcher->match($query, $card->title);
            if ($match !== null && !$match->isEmpty()) {
                $card = $card->withStyledTitle($highlighter->highlight(
                    $match,
                    static fn (string $matched): string => Style::new()->bold()->render($matched),
                ));
            }

            $cards[$index] = $card;
        }

        return $cards;
    }

    private function onResize(int $cols, int $rows): array
    {
        $grid = $this->grid->withViewport(self::viewportCols($cols), self::viewportRows($rows));

        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;
        $next->grid = $grid;

        return $next->hasSearched ? $next->afterGridChange($grid) : [$next, null];
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
        // The content panel fills the frame; window the grid to that body height
        // less the search input line + the result-count line (2).
        return max(self::POSTER_HEIGHT + 2, Chrome::bodyHeight($rows) - 2);
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Search';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function searchText(): string
    {
        return $this->searchText;
    }

    public function hasSearched(): bool
    {
        return $this->hasSearched;
    }

    public function grid(): PosterGrid
    {
        return $this->grid;
    }

    public function query(): MediaQuery
    {
        return $this->query;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}

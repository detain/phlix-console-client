<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\StatsFailedMsg;
use Phlix\Console\Msg\StatsLoadedMsg;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * A read-only statistics panel: the libraries the app already fetches, grouped by
 * type into a borderless {@see Table} (Type · Libraries · Items) with a TOTAL line
 * beneath. Reached from the command palette. The list is fetched once via
 * {@see LibrariesStore::all()} (the same cache the browse home and palette use);
 * there is no selection or drill-in — Esc/q go back.
 *
 * Stable collaborators are readonly; the aggregated rows + totals are private
 * mutable view state, set once on load via clone-mutate (the established screen
 * idiom). The screen is purely derived from the library list, so there is no
 * cursor / paging.
 */
final class StatsScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load library stats.';
    private const HINT = 'Esc  back';
    private const COL_WIDTH = 12;

    /**
     * The friendly label per raw library type, in display order. An unknown type
     * sorts after these (in first-seen order) and shows `ucfirst($type)`.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'movie' => 'Movies',
        'tv' => 'TV',
        'series' => 'Series',
        'music' => 'Music',
        'book' => 'Books',
        'audiobook' => 'Audiobooks',
        'photo' => 'Photos',
    ];

    /**
     * The aggregated per-type rows, or null until the libraries load.
     *
     * @var list<array{label: string, libraries: int, items: int}>|null
     */
    private ?array $stats = null;
    private int $totalLibraries = 0;
    private int $totalItems = 0;
    private bool $loaded = false;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly LibrariesStore $libraries,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return Cmd::promise(fn () => $this->libraries->all()->then(
            static fn (array $libraries): Msg => new StatsLoadedMsg($libraries),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new StatsFailedMsg(self::LOAD_FAILED),
        ));
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof StatsLoadedMsg) {
            return [$this->withStats($msg->libraries), null];
        }
        if ($msg instanceof StatsFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Stats', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        return [$this, null];
    }

    // ---- aggregation ---------------------------------------------------

    /**
     * Group $libraries by type, summing the library count + item count per type,
     * and total them. Known types render in the fixed {@see LABELS} order; any
     * unknown type follows in first-seen order, labelled `ucfirst($type)`.
     *
     * @param list<Library> $libraries
     * @return array{rows: list<array{label: string, libraries: int, items: int}>, totalLibraries: int, totalItems: int}
     */
    private static function aggregate(array $libraries): array
    {
        /** @var array<string, array{label: string, libraries: int, items: int}> $byType */
        $byType = [];
        $totalLibraries = 0;
        $totalItems = 0;

        foreach ($libraries as $library) {
            $type = $library->type;
            if (!isset($byType[$type])) {
                $byType[$type] = ['label' => self::label($type), 'libraries' => 0, 'items' => 0];
            }
            ++$byType[$type]['libraries'];
            $byType[$type]['items'] += $library->itemCount;
            ++$totalLibraries;
            $totalItems += $library->itemCount;
        }

        $rows = [];
        // Known types first, in the canonical LABELS order …
        foreach (array_keys(self::LABELS) as $type) {
            if (isset($byType[$type])) {
                $rows[] = $byType[$type];
                unset($byType[$type]);
            }
        }
        // … then any unknown types, in first-seen order (insertion order is preserved).
        foreach ($byType as $row) {
            $rows[] = $row;
        }

        return ['rows' => $rows, 'totalLibraries' => $totalLibraries, 'totalItems' => $totalItems];
    }

    /** The friendly label for a raw type (`ucfirst` for anything not in the table). */
    private static function label(string $type): string
    {
        return self::LABELS[$type] ?? ucfirst($type);
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if (!$this->loaded) {
            return "\n  Loading stats…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}";
        }
        if ($this->stats === null || $this->stats === []) {
            return "\n  No libraries.";
        }

        $rows = [];
        foreach ($this->stats as $row) {
            $rows[] = [$row['label'], (string) $row['libraries'], (string) $row['items']];
        }

        // A read-only table: no row is highlighted (the panel has no cursor).
        $table = Table::render([
            ['title' => 'Type', 'width' => 0],
            ['title' => 'Libraries', 'width' => self::COL_WIDTH, 'align' => 'right'],
            ['title' => 'Items', 'width' => self::COL_WIDTH, 'align' => 'right'],
        ], $rows, 0, $this->cols - 4, $this->viewportRows(), selectable: false);

        // A blank spacer then a plain (ANSI-safe) totals line beneath the table.
        return $table . "\n\n" . $this->totalsLine();
    }

    /** A plain `Total: N libraries · M items` summary line (kept ANSI-safe). */
    private function totalsLine(): string
    {
        $libraries = $this->totalLibraries === 1 ? 'library' : 'libraries';
        $items = $this->totalItems === 1 ? 'item' : 'items';

        return 'Total: ' . $this->totalLibraries . ' ' . $libraries . ' · ' . $this->totalItems . ' ' . $items;
    }

    private function viewportRows(): int
    {
        // The content panel fills the frame; window the type rows to the body height
        // less the table's own header + separator (2) and the blank + totals line
        // (2) beneath it, so the totals line is never pushed off-screen.
        return max(1, Chrome::bodyHeight($this->rows) - 4);
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    /** @param list<Library> $libraries */
    private function withStats(array $libraries): self
    {
        $aggregated = self::aggregate($libraries);

        $next = clone $this;
        $next->stats = $aggregated['rows'];
        $next->totalLibraries = $aggregated['totalLibraries'];
        $next->totalItems = $aggregated['totalItems'];
        $next->loaded = true;
        $next->error = null;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = true;

        return $next;
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Stats';
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

    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * The aggregated per-type rows (label / library count / item sum), or null
     * before the libraries load.
     *
     * @return list<array{label: string, libraries: int, items: int}>|null
     */
    public function stats(): ?array
    {
        return $this->stats;
    }

    public function totalLibraries(): int
    {
        return $this->totalLibraries;
    }

    public function totalItems(): int
    {
        return $this->totalItems;
    }
}

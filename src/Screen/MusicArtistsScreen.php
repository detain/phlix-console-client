<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\MusicArtist;
use Phlix\Console\Msg\MusicArtistsRangeLoadedMsg;
use Phlix\Console\Msg\MusicArtistsFailedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenMusicForArtistMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\MusicArtistsRange;
use Phlix\Console\Store\MusicArtistsStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * A music library's artist list, rendered as a borderless sugar-table via
 * {@see Table} (Artist · Albums) with reverse-video row selection.
 * ↑/↓ move the selection (fetching more pages on scroll), Enter opens the
 * artist's album list (an {@see OpenMusicForArtistMsg}), Esc/q go back.
 *
 * The artist list is paged via {@see MusicArtistsStore::ensureRange()}: only the
 * visible window (plus overscan) is fetched, so even a large artist library
 * scrolls smoothly. Stable collaborators are readonly; the mutable view state
 * is private and copied via clone-mutate (the established screen idiom).
 */
final class MusicArtistsScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const HINT = '↑↓  artists      ⏎  open      Esc  back';
    // Fixed columns; the flex Artist column fills whatever is left.
    private const ALBUMS_WIDTH = 8;
    private const PAGE_LIMIT = 100;
    private const OVERSCAN = 5;
    private const LOAD_MORE_FAILED = "Couldn't load more artists.";

    /**
     * Sparse array of loaded artists, keyed by absolute index in the full result set.
     * @var array<int, MusicArtist>
     */
    private array $artists = [];
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
        private readonly MusicArtistsStore $artistsStore,
        private int $cols = 80,
        private int $rows = 24,
    ) {
        $this->requestedRange = [0, $this->initialWindowEnd()];
    }

    public function init(): \Closure
    {
        return $this->fetchRange(0, $this->initialWindowEnd());
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof MusicArtistsRangeLoadedMsg) {
            return $this->onRange($msg->range, $msg->generation);
        }
        if ($msg instanceof MusicArtistsFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Music Artists', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Enter) {
            $artist = $this->artists[$this->selected] ?? null;

            return $artist === null
                ? [$this, null]
                : [$this, Cmd::send(new OpenMusicForArtistMsg($artist->name))];
        }
        if ($msg->type === KeyType::Up) {
            return $this->moveSelection(-1);
        }
        if ($msg->type === KeyType::Down) {
            return $this->moveSelection(1);
        }

        return [$this, null];
    }

    /**
     * @return array{self, ?\Closure}
     */
    private function moveSelection(int $delta): array
    {
        $count = $this->total > 0 ? $this->total : count($this->artists);
        if ($count === 0) {
            return [$this, null];
        }

        $nextSelected = max(0, min($count - 1, $this->selected + $delta));

        if ($nextSelected === $this->selected) {
            return [$this, null];
        }

        $next = clone $this;
        $next->selected = $nextSelected;

        // If the new selection is outside our currently loaded range, fetch more.
        $cmds = [];
        if ($nextSelected < $next->requestedRange[0] || $nextSelected > $next->requestedRange[1]) {
            $fetchEnd = min($count - 1, $nextSelected + $this->fetchAhead());
            $cmds[] = $this->fetchRange($nextSelected - $this->fetchAhead(), $fetchEnd);
            $next->requestedRange = [$nextSelected - $this->fetchAhead(), $fetchEnd];
        }

        return [$next, $cmds === [] ? null : Cmd::batch(...$cmds)];
    }

    // ---- data ---------------------------------------------------------

    private function fetchRange(int $start, int $end): \Closure
    {
        $generation = $this->generation;

        return Cmd::promise(fn () => $this->artistsStore->ensureRange($start, $end, self::PAGE_LIMIT)->then(
            static fn (MusicArtistsRange $range): Msg => new MusicArtistsRangeLoadedMsg($range, $generation),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new MusicArtistsFailedMsg(self::LOAD_MORE_FAILED),
        ));
    }

    /**
     * @return array{self, ?\Closure}
     */
    private function onRange(MusicArtistsRange $range, int $generation): array
    {
        if ($generation !== $this->generation) {
            return [$this, null]; // a superseded query's result
        }

        // Splice artists in at their absolute indices.
        $next = clone $this;
        $next->artists = $this->artists;
        foreach ($range->artists as $index => $artist) {
            $next->artists[$index] = $artist;
        }
        $next->total = $range->total;
        $next->loaded = true;
        $next->error = null;

        // Clamp selection to valid range.
        $count = $range->total > 0 ? $range->total : count($next->artists);
        if ($count > 0 && $next->selected >= $count) {
            $next->selected = $count - 1;
        }

        // Extend the requested window to cover what we now have loaded.
        $minIndex = $next->artists === [] ? 0 : min(array_keys($next->artists));
        $maxIndex = $next->artists === [] ? 0 : max(array_keys($next->artists));
        $next->requestedRange = [$minIndex, $maxIndex];

        return [$next, null];
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if (!$this->loaded) {
            return "\n  Loading artists…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}";
        }
        if ($this->artists === [] && $this->total === 0) {
            return "\n  No artists in this library.";
        }

        $vRows = $this->viewportRows();
        $start = max(0, $this->selected - $vRows + 1);
        $end = min($this->total > 0 ? $this->total - 1 : count($this->artists) - 1, $this->selected + $vRows - 1);

        $rows = [];
        for ($i = $start; $i <= $end; $i++) {
            $artist = $this->artists[$i] ?? null;
            if ($artist === null) {
                // Gap in the sparse array — shouldn't happen, but be defensive.
                continue;
            }
            $rows[] = [
                $artist->name,
                (string) $artist->albumCount,
            ];
        }

        // Table::render expects list<list<string>> and a single selected index
        // relative to the visible rows (not the full list). Remap selection.
        $relativeSelected = $this->selected - $start;

        return Table::render([
            ['title' => 'Artist', 'width' => 0],
            ['title' => 'Albums', 'width' => self::ALBUMS_WIDTH, 'align' => 'right'],
        ], $rows, $relativeSelected, $this->cols - 4, $vRows);
    }

    private function viewportRows(): int
    {
        return max(1, Chrome::bodyHeight($this->rows) - 2);
    }

    private function initialWindowEnd(): int
    {
        return max(0, $this->viewportRows() - 1 + self::OVERSCAN);
    }

    private function fetchAhead(): int
    {
        return max(1, $this->viewportRows() - 1 + self::OVERSCAN);
    }

    // ---- immutable copies (clone-mutate) -------------------------------

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
        return 'Artists';
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

    public function selectedArtist(): ?MusicArtist
    {
        return $this->artists[$this->selected] ?? null;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function total(): int
    {
        return $this->total;
    }

    /** @return array<int, MusicArtist> */
    public function artistsByIndex(): array
    {
        return $this->artists;
    }
}

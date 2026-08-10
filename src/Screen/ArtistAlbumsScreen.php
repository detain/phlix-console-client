<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Msg\MusicFailedMsg;
use Phlix\Console\Msg\MusicRangeLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenAlbumMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\ArtistAlbumsStore;
use Phlix\Console\Store\MusicRange;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * An artist's album list, rendered as a borderless sugar-table via
 * {@see Table} (Album · Year · Tracks) with reverse-video row selection.
 * ↑/↓ move the selection (fetching more pages on scroll), Enter opens the
 * album's track list (an {@see OpenAlbumMsg}), Esc/q go back.
 */
final class ArtistAlbumsScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const HINT = '↑↓  select      ⏎  open      Esc  back';
    private const YEAR_WIDTH = 6;
    private const TRACKS_WIDTH = 7;
    private const PAGE_LIMIT = 100;
    private const OVERSCAN = 5;
    private const LOAD_MORE_FAILED = "Couldn't load more albums.";

    /**
     * Sparse array of loaded albums, keyed by absolute index.
     * @var array<int, Album>
     */
    private array $albums = [];
    private int $total = 0;
    private int $selected = 0;
    private bool $loaded = false;
    private ?string $error = null;
    private int $generation = 0;
    /** @var array{0:int,1:int} */
    private array $requestedRange;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly ArtistAlbumsStore $store,
        private readonly string $artistName,
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
        if ($msg instanceof MusicRangeLoadedMsg) {
            return $this->onRange($msg->range, $msg->generation);
        }
        if ($msg instanceof MusicFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame($this->artistName, $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Enter) {
            $album = $this->albums[$this->selected] ?? null;

            return $album === null
                ? [$this, null]
                : [$this, Cmd::send(new OpenAlbumMsg($album))];
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
        $count = $this->total > 0 ? $this->total : count($this->albums);
        if ($count === 0) {
            return [$this, null];
        }

        $nextSelected = max(0, min($count - 1, $this->selected + $delta));

        if ($nextSelected === $this->selected) {
            return [$this, null];
        }

        $next = clone $this;
        $next->selected = $nextSelected;

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

        return Cmd::promise(fn () => $this->store->ensureRange($start, $end, self::PAGE_LIMIT)->then(
            static fn (MusicRange $range): Msg => new MusicRangeLoadedMsg($range, $generation),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new MusicFailedMsg(self::LOAD_MORE_FAILED),
        ));
    }

    /**
     * @return array{self, ?\Closure}
     */
    private function onRange(MusicRange $range, int $generation): array
    {
        if ($generation !== $this->generation) {
            return [$this, null];
        }

        $next = clone $this;
        $next->albums = $this->albums;
        foreach ($range->albums as $index => $album) {
            $next->albums[$index] = $album;
        }
        $next->total = $range->total;
        $next->loaded = true;
        $next->error = null;

        $count = $range->total > 0 ? $range->total : count($next->albums);
        if ($count > 0 && $next->selected >= $count) {
            $next->selected = $count - 1;
        }

        $minIndex = $next->albums === [] ? 0 : min(array_keys($next->albums));
        $maxIndex = $next->albums === [] ? 0 : max(array_keys($next->albums));
        $next->requestedRange = [$minIndex, $maxIndex];

        return [$next, null];
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if (!$this->loaded) {
            return "\n  Loading albums…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}";
        }
        if ($this->albums === [] && $this->total === 0) {
            return "\n  No albums by {$this->artistName}.";
        }

        $vRows = $this->viewportRows();
        $start = max(0, $this->selected - $vRows + 1);
        $end = min($this->total > 0 ? $this->total - 1 : count($this->albums) - 1, $this->selected + $vRows - 1);

        $rows = [];
        for ($i = $start; $i <= $end; $i++) {
            $album = $this->albums[$i] ?? null;
            if ($album === null) {
                continue;
            }
            $rows[] = [
                $album->name,
                $album->year !== null ? (string) $album->year : '—',
                (string) $album->trackCount,
            ];
        }

        $relativeSelected = $this->selected - $start;

        return Table::render([
            ['title' => 'Album', 'width' => 0],
            ['title' => 'Year', 'width' => self::YEAR_WIDTH, 'align' => 'right'],
            ['title' => 'Tracks', 'width' => self::TRACKS_WIDTH, 'align' => 'right'],
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
        return $this->artistName;
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

    public function selectedAlbum(): ?Album
    {
        return $this->albums[$this->selected] ?? null;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function total(): int
    {
        return $this->total;
    }

    /** @return array<int, Album> */
    public function albumsByIndex(): array
    {
        return $this->albums;
    }
}
<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Msg\AlbumsLoadedMsg;
use Phlix\Console\Msg\MusicFailedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenAlbumMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Store\MusicStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * A music library's album list, rendered as a borderless sugar-table via
 * {@see Table} (Album · Artist · Year · Tracks) with reverse-video row
 * selection. Music has no cover art server-side, so the screen is text-forward.
 * ↑/↓ move the selection, Enter opens the album's track
 * list (an {@see OpenAlbumMsg} the App turns into an AlbumScreen), Esc/q go back.
 *
 * The album list is fetched once via {@see MusicStore::albums()} (the server
 * returns every album, with its tracks, in one call). Stable collaborators are
 * readonly; the mutable view state is private and copied via clone-mutate (the
 * established screen idiom).
 */
final class MusicScreen implements Breadcrumbed
{
    use SubscriptionCapable;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const HINT = '↑↓  select      ⏎  open      Esc  back';
    // Fixed columns; the flex Album column fills whatever is left.
    private const ARTIST_WIDTH = 22;
    private const YEAR_WIDTH = 6;
    private const TRACKS_WIDTH = 7;

    /** @var list<Album> */
    private array $albums = [];
    private int $selected = 0;
    private bool $loaded = false;
    private ?string $error = null;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly MusicStore $music,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): ?\Closure
    {
        return Cmd::promise(fn () => $this->music->albums()->then(
            static fn (array $albums): Msg => new AlbumsLoadedMsg($albums),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new MusicFailedMsg('Could not load this library.'),
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
        if ($msg instanceof AlbumsLoadedMsg) {
            return [$this->withAlbums($msg->albums), null];
        }
        if ($msg instanceof MusicFailedMsg) {
            return [$this->withError($msg->reason), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Music', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs);
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->albums === []
                ? [$this, null]
                : [$this, Cmd::send(new OpenAlbumMsg($this->albums[$this->selected]))];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }

        return [$this, null];
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->albums);
        if ($count === 0) {
            return $this;
        }
        $selected = max(0, min($count - 1, $this->selected + $delta));
        if ($selected === $this->selected) {
            return $this;
        }
        $next = clone $this;
        $next->selected = $selected;

        return $next;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if (!$this->loaded) {
            return "\n  Loading music…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}";
        }
        if ($this->albums === []) {
            return "\n  No albums in this library.";
        }

        $rows = [];
        foreach ($this->albums as $album) {
            $rows[] = [
                $album->name,
                $album->artist ?? '—',
                $album->year !== null ? (string) $album->year : '—',
                (string) $album->trackCount,
            ];
        }

        return Table::render([
            ['title' => 'Album', 'width' => 0],
            ['title' => 'Artist', 'width' => self::ARTIST_WIDTH],
            ['title' => 'Year', 'width' => self::YEAR_WIDTH, 'align' => 'right'],
            ['title' => 'Tracks', 'width' => self::TRACKS_WIDTH, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());
    }

    private function viewportRows(): int
    {
        // The content panel fills the frame; window the table to that body height
        // less the table's own header + separator (2), so the selected row is
        // never clipped.
        return max(1, Chrome::bodyHeight($this->rows) - 2);
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    /** @param list<Album> $albums */
    private function withAlbums(array $albums): self
    {
        $next = clone $this;
        $next->albums = $albums;
        $next->loaded = true;
        $next->error = null;
        $next->selected = $albums === [] ? 0 : min($this->selected, count($albums) - 1);

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
        return 'Music';
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

    /** @return list<Album> */
    public function albums(): array
    {
        return $this->albums;
    }
}

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
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;

/**
 * A music library's album list, rendered as a {@see Table} (Album · Artist ·
 * Year · Tracks). Music has no cover art server-side, so the screen is
 * text-forward. ↑/↓ move the selection, Enter opens the album's track list (an
 * {@see OpenAlbumMsg} the App turns into an AlbumScreen), Esc/q go back.
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
    // Small fixed columns; the Album/Artist columns share whatever is left.
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

        return $this->table()->View();
    }

    private function table(): Table
    {
        $albumWidth = $this->albumColumnWidth();
        $artistWidth = $this->artistColumnWidth();

        $rows = [];
        foreach ($this->albums as $album) {
            $rows[] = Row::new(RowData::from([
                'album' => Width::truncate($album->name, $albumWidth),
                'artist' => Width::truncate($album->artist ?? '—', $artistWidth),
                'year' => $album->year !== null ? (string) $album->year : '—',
                'tracks' => (string) $album->trackCount,
            ]));
        }

        return Table::withColumns([
            Column::new('album', 'Album', $albumWidth)->withAlignLeft(),
            Column::new('artist', 'Artist', $artistWidth)->withAlignLeft(),
            Column::new('year', 'Year', self::YEAR_WIDTH),
            Column::new('tracks', 'Tracks', self::TRACKS_WIDTH),
        ])
            ->withRows($rows)
            ->withSelectable()
            ->withSelectedIndex($this->selected)
            ->withViewportHeight($this->viewportRows())
            // sugar-boxer (which Chrome composes the body with) is ANSI-width
            // UNAWARE, so it clips a line mid-escape when cells carry truecolor
            // SGR. A plain header emits no per-cell color, keeping the whole
            // table intact; the selected row's short reverse-video still shows.
            ->withHeaderStyle('');
    }

    /** The room left for the two flexible columns after the fixed ones + borders. */
    private function flexibleWidth(): int
    {
        // The table draws 2 border columns plus a 1-col separator between each of
        // the 4 columns (3 separators); subtract those and the two fixed columns.
        $fixed = self::YEAR_WIDTH + self::TRACKS_WIDTH;
        $chrome = 2 + 3; // borders + inter-column separators

        return max(20, ($this->cols - 4) - $fixed - $chrome);
    }

    private function albumColumnWidth(): int
    {
        // Album gets the larger share of the flexible room.
        return max(12, (int) ceil($this->flexibleWidth() * 0.6));
    }

    private function artistColumnWidth(): int
    {
        return max(8, $this->flexibleWidth() - $this->albumColumnWidth());
    }

    private function viewportRows(): int
    {
        // Reserve the frame chrome (4) plus the table's own header + separator +
        // top/bottom border (4) so the visible rows fit the content region.
        return max(1, $this->rows - 4 - 4);
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

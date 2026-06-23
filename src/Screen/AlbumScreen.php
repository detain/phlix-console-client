<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\Track;
use Phlix\Console\Msg\AudioSkipMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\PlayTrackMsg;
use Phlix\Console\Msg\ToggleAudioMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;

/**
 * A single album's track list, rendered as a borderless sugar-table via
 * {@see Table} (# · Title · Duration) with reverse-video row selection, beneath a
 * one-line meta header (artist · year · N tracks).
 * The {@see Album} carries its own tracks, so the screen needs no track fetch.
 *
 * The screen is a PURE LIST: it owns no playback. Music audio now lives on the
 * App (a persistent {@see \Phlix\Console\Audio\MusicSession} shown by the
 * {@see \Phlix\Console\Ui\NowPlayingBar} on every screen), so playback continues
 * when the user leaves the album. The screen just EMITS Msgs the App acts on:
 *
 * - Enter → {@see PlayTrackMsg} (play the selected track)
 * - Space → {@see ToggleAudioMsg} (pause/resume the App's session)
 * - n / p → {@see AudioSkipMsg} (next / previous track)
 * - Esc / q → {@see NavigateBackMsg} (the audio keeps playing — the bar shows it)
 *
 * ↑/↓ move the selection (independent of what is playing; the screen no longer
 * knows the playback state). Stable collaborators are readonly; mutable view
 * state (the selection) is private and copied via clone-mutate.
 */
final class AlbumScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const HINT = '↑↓  select      ⏎  play      space  pause · n/p  next/prev      Esc  back';
    private const NUM_WIDTH = 4;
    private const DURATION_WIDTH = 10;

    private int $selected = 0;
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly Album $album,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): ?\Closure
    {
        return null; // the Album already carries its tracks
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame($this->album->name, $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            // The audio keeps playing on leave — the App owns it now (the
            // now-playing bar carries it across navigation).
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === ' ') {
            return [$this, Cmd::send(new ToggleAudioMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'n') {
            return [$this, Cmd::send(new AudioSkipMsg(1))];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'p') {
            return [$this, Cmd::send(new AudioSkipMsg(-1))];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->onEnter();
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }

        return [$this, null];
    }

    /** Enter on the selected track: ask the App to play it (a no-op on an empty album). */
    private function onEnter(): array
    {
        if ($this->album->tracks === []) {
            return [$this, null];
        }

        return [$this, Cmd::send(new PlayTrackMsg($this->album, $this->selected))];
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->album->tracks);
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
        // The album name is already in the Chrome title bar, so the content header
        // is a single line (the album meta) plus a blank — matching DetailScreen's
        // 2-line reservation so the table is not clipped.
        $header = Width::truncate($this->metaLine(), max(1, $this->cols - 4));

        if ($this->album->tracks === []) {
            return $header . "\n\n  No tracks on this album.";
        }

        return $header . "\n\n" . $this->trackTable();
    }

    private function metaLine(): string
    {
        $count = count($this->album->tracks);
        $parts = [];
        if ($this->album->artist !== null && $this->album->artist !== '') {
            $parts[] = $this->album->artist;
        }
        if ($this->album->year !== null) {
            $parts[] = (string) $this->album->year;
        }
        $parts[] = $count . ' track' . ($count === 1 ? '' : 's');

        return implode('   ·   ', $parts);
    }

    private function trackTable(): string
    {
        $rows = [];
        foreach ($this->album->tracks as $ordinal => $track) {
            $rows[] = [
                $this->trackNumberLabel($track, $ordinal),
                $track->title,
                $track->durationLabel(),
            ];
        }

        return Table::render([
            ['title' => '#', 'width' => self::NUM_WIDTH, 'align' => 'right'],
            ['title' => 'Title', 'width' => 0],
            ['title' => 'Duration', 'width' => self::DURATION_WIDTH, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());
    }

    /** The track's own number, falling back to its 1-based position in the list. */
    private function trackNumberLabel(Track $track, int $ordinal): string
    {
        return (string) ($track->trackNumber ?? ($ordinal + 1));
    }

    private function viewportRows(): int
    {
        // The content panel fills the frame; window the table to that body height
        // less the header line + blank (2) and the table's own header + separator
        // (2), so the selected row is never clipped by the frame.
        return max(1, Chrome::bodyHeight($this->rows) - 4);
    }

    // ---- immutable copies (clone-mutate) -------------------------------

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
        return $this->album->name;
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function album(): Album
    {
        return $this->album;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function selectedTrack(): ?Track
    {
        return $this->album->tracks[$this->selected] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\Track;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\TableView;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;

/**
 * A single album's track list, rendered as a plain-text {@see TableView} (# ·
 * Title · Duration) beneath a one-line meta header (artist · year · N tracks).
 * The {@see Album} carries its own tracks, so the screen needs no fetch —
 * init() returns null.
 *
 * ↑/↓ move the selection; Esc/q go back. Enter is an inert placeholder in this
 * step — audio playback arrives in the next update (M3) — so it surfaces an
 * informational toast rather than doing nothing silently. Stable collaborators
 * are readonly; the mutable view state is private and copied via clone-mutate.
 */
final class AlbumScreen implements Breadcrumbed
{
    use SubscriptionCapable;

    private const HINT = '↑↓  select      ⏎  play      Esc  back';
    private const PLAYBACK_SOON = 'Audio playback arrives in the next update';
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
        return Chrome::frame($this->album->name, $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs);
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Enter) {
            // M2 has no audio engine yet — tell the user where playback is going
            // instead of a silent no-op (M3 wires this Enter to the AudioPlayer).
            return $this->album->tracks === []
                ? [$this, null]
                : [$this, Cmd::send(ShowToastMsg::info(self::PLAYBACK_SOON))];
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
        // is a single meta line plus a blank — matching DetailScreen's container
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

        return TableView::render([
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
        // Window to the frame's REAL content height (a fraction of $rows, not
        // rows-N), less the meta line + blank (2) and the table's own header +
        // separator (2), so the selected row is never clipped by the frame.
        return max(1, Chrome::contentHeight($this->cols, $this->rows) - 2 - 2);
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

<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\Track;
use Phlix\Console\Msg\AudioFailedMsg;
use Phlix\Console\Msg\AudioStartedMsg;
use Phlix\Console\Msg\AudioTickMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\TableView;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Reel\AudioPlayer;

/**
 * A single album's track list, rendered as a plain-text {@see TableView} (# ·
 * Title · Duration) beneath a one-line meta header (artist · year · N tracks).
 * The {@see Album} carries its own tracks, so the screen needs no track fetch.
 *
 * Enter plays the selected track: the screen fetches that media item's signed
 * direct-play `stream_url` ({@see MediaStore::item}, no auth header needed) and
 * feeds it verbatim to a sugar-reel {@see AudioPlayer} (ffplay/mpv) — the same
 * direct-play crux the video player uses. Playback is screen-local: it plays
 * while the album is open and stops on leave (Esc/q, a stack pop, or Ctrl-C),
 * because the screen is {@see Teardownable} and the App tears down a popped
 * frame. AudioPlayer exposes no playhead clock, so the elapsed position is
 * ESTIMATED by counting 1-second {@see AudioTickMsg}s while playing.
 *
 * ↑/↓ move the selection (independent of which track is playing); Space toggles
 * pause; n/p move playback to the next/previous track; Esc/q go back. Stable
 * collaborators are readonly; mutable view state is private and copied via
 * clone-mutate — only {@see teardown()} mutates `$this` in place (the player
 * lifecycle, like PlayerScreen).
 */
final class AlbumScreen implements Breadcrumbed, Teardownable
{
    use SubscriptionCapable;

    private const HINT = '↑↓  select      ⏎  play      space  pause · n/p  next/prev      Esc  back';
    private const PLAY_FAILED = 'Could not play this track';
    private const NUM_WIDTH = 4;
    private const DURATION_WIDTH = 10;

    private int $selected = 0;
    /** @var list<string> */
    private array $crumbs = [];

    /** Index of the playing track, or null when nothing is playing. */
    private ?int $playing = null;
    private ?AudioPlayer $audio = null;
    private bool $paused = false;
    /** Estimated elapsed seconds (counted from 1s ticks while playing). */
    private int $position = 0;
    /**
     * The current heartbeat generation. Bumped on every (re)start of playback
     * (start/resume/n/p/auto-advance) so a tick armed by a superseded chain is
     * dropped as stale — guarding against two heartbeats running at once.
     */
    private int $audioEpoch = 0;
    /** True while a track's stream URL is being fetched. */
    private bool $loading = false;
    private bool $tornDown = false;

    /**
     * @param \Closure(string $url): AudioPlayer $audioFactory
     *        Builds the audio player for a resolved URL (injected so tests use a
     *        recording fake instead of spawning ffplay/mpv).
     */
    public function __construct(
        private readonly Album $album,
        private readonly MediaStore $media,
        private readonly string $baseUrl,
        private readonly \Closure $audioFactory,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    /**
     * The real factory: a sugar-reel {@see AudioPlayer} over the resolved stream
     * URL (it spawns ffplay/mpv on start(), or silently no-ops if neither is
     * installed).
     *
     * @return \Closure(string $url): AudioPlayer
     */
    public static function productionAudioFactory(): \Closure
    {
        return static fn (string $url): AudioPlayer => new AudioPlayer($url);
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
        if ($msg instanceof AudioStartedMsg) {
            return $this->onAudioStarted($msg->index, $msg->url);
        }
        if ($msg instanceof AudioFailedMsg) {
            return $this->onAudioFailed();
        }
        if ($msg instanceof AudioTickMsg) {
            return $this->onAudioTick($msg->epoch);
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
            // Stop the audio before popping (mirrors PlayerScreen) so leaving the
            // album never leaks an ffplay/mpv subprocess.
            $this->teardown();

            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === ' ') {
            return $this->togglePause();
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'n') {
            return $this->playRelative(1);
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'p') {
            return $this->playRelative(-1);
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

    /** Enter on the selected track: toggle pause if it's playing, else start it. */
    private function onEnter(): array
    {
        if ($this->album->tracks === []) {
            return [$this, null];
        }
        if ($this->playing === $this->selected) {
            return $this->togglePause();
        }

        return $this->play($this->selected);
    }

    /**
     * Start track $index: fetch its signed stream URL, then (on success) spawn
     * the player via {@see onAudioStarted}. Out-of-range indices are a no-op.
     */
    private function play(int $index): array
    {
        $track = $this->album->tracks[$index] ?? null;
        if ($track === null) {
            return [$this, null];
        }

        $next = clone $this;
        $next->loading = true;
        // Supersede any running heartbeat while the new track resolves, so the
        // outgoing track can't keep ticking (and auto-advancing) mid-fetch.
        $next->audioEpoch = $this->audioEpoch + 1;

        return [$next, $this->fetchStreamCmd($index, $track->id)];
    }

    /**
     * Move playback to the track $delta away from the one playing (clamped to the
     * album bounds). A no-op when nothing is playing or the move runs off an end.
     */
    private function playRelative(int $delta): array
    {
        if ($this->playing === null) {
            return [$this, null];
        }
        $target = $this->playing + $delta;
        if ($target < 0 || $target >= count($this->album->tracks)) {
            return [$this, null];
        }

        return $this->play($target);
    }

    /** Toggle pause on the playing track; a no-op when nothing is playing. */
    private function togglePause(): array
    {
        if ($this->playing === null || $this->audio === null) {
            return [$this, null];
        }

        $next = clone $this;
        $next->paused = !$this->paused;
        // Bump the epoch either way: pausing must invalidate the in-flight tick
        // (so it can't fire once more after pause), and resuming starts a fresh
        // heartbeat that no leftover tick can double.
        $next->audioEpoch = $this->audioEpoch + 1;
        if ($next->paused) {
            $this->audio->pause();

            return [$next, null]; // hold the position; stop the tick
        }

        $this->audio->resume();

        return [$next, $this->tickCmd($next->audioEpoch)];
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

    // ---- audio lifecycle -----------------------------------------------

    /**
     * Resolve track $index's signed stream URL via the detail endpoint, then →
     * {@see AudioStartedMsg}. A missing/empty URL or a non-auth error becomes an
     * {@see AudioFailedMsg}; an auth failure surfaces as a session expiry so the
     * App can re-authenticate.
     */
    private function fetchStreamCmd(int $index, string $trackId): \Closure
    {
        return Cmd::promise(fn (): PromiseInterface => $this->media->item($trackId)->then(
            function (MediaItem $item) use ($index): Msg {
                $url = $item->streamUrl;
                if ($url === null || $url === '') {
                    return new AudioFailedMsg('no stream url');
                }

                return new AudioStartedMsg($index, $this->resolveUrl($url));
            },
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg('Your session expired. Please sign in again.')
                : new AudioFailedMsg($e->getMessage()),
        ));
    }

    /** The stream URL resolved — stop any current player, spawn the new one, arm the tick. */
    private function onAudioStarted(int $index, string $url): array
    {
        $this->audio?->stop();
        $player = ($this->audioFactory)($url);
        $player->start();

        $next = clone $this;
        $next->audio = $player;
        $next->playing = $index;
        $next->paused = false;
        $next->position = 0;
        $next->loading = false;
        // A fresh heartbeat generation for the newly-started track.
        $next->audioEpoch = $this->audioEpoch + 1;

        return [$next, $this->tickCmd($next->audioEpoch)];
    }

    /** A track failed to resolve/play — clear the spinner, toast, leave playback alone. */
    private function onAudioFailed(): array
    {
        $next = clone $this;
        $next->loading = false;

        return [$next, Cmd::send(ShowToastMsg::error(self::PLAY_FAILED))];
    }

    /**
     * One playback second elapsed: advance the estimated position and re-arm the
     * tick. At/after the track's known duration, auto-advance to the next track
     * (or stop at the last one). Ignored when not playing or paused.
     */
    private function onAudioTick(int $epoch): array
    {
        // Drop a tick from a superseded heartbeat (a stale chain after a
        // start/resume/pause/n/p), or when not actively playing.
        if ($epoch !== $this->audioEpoch || $this->playing === null || $this->paused) {
            return [$this, null];
        }

        $next = clone $this;
        $next->position = $this->position + 1;

        $duration = $this->playingTrack()?->durationSecs;
        if ($duration !== null && $next->position >= $duration) {
            // Track finished — advance to the next, or stop at the end.
            if (($this->playing + 1) < count($this->album->tracks)) {
                return $next->play($this->playing + 1);
            }
            $next->stopPlaybackInPlace();

            return [$next, null];
        }

        // Continue the SAME generation (no bump here).
        return [$next, $this->tickCmd($next->audioEpoch)];
    }

    /** The currently-playing track, or null when nothing is playing. */
    private function playingTrack(): ?Track
    {
        return $this->playing === null ? null : ($this->album->tracks[$this->playing] ?? null);
    }

    /**
     * Stop and clear playback on this instance (only ever called on a freshly
     * cloned screen, like PlayerScreen's in-place resume).
     */
    private function stopPlaybackInPlace(): void
    {
        $this->audio?->stop();
        $this->audio = null;
        $this->playing = null;
        $this->paused = false;
        $this->position = 0;
        // Invalidate any tick still in flight so it can't resurrect playback.
        $this->audioEpoch++;
    }

    public function teardown(): void
    {
        if ($this->tornDown) {
            return;
        }
        $this->tornDown = true;
        $this->audio?->stop();
    }

    private function tickCmd(int $epoch): \Closure
    {
        return Cmd::tick(1.0, static fn (): Msg => new AudioTickMsg($epoch));
    }

    /** Resolve a (possibly relative) URL against the server base; absolute/empty pass through. */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        // The album name is already in the Chrome title bar, so the content header
        // is a single line (the now-playing line while a track plays, else the
        // album meta) plus a blank — matching DetailScreen's 2-line reservation so
        // the table is not clipped.
        $header = Width::truncate($this->headerLine(), max(1, $this->cols - 4));

        if ($this->album->tracks === []) {
            return $header . "\n\n  No tracks on this album.";
        }

        return $header . "\n\n" . $this->trackTable();
    }

    /** The now-playing line when a track plays, otherwise the album meta line. */
    private function headerLine(): string
    {
        $track = $this->playingTrack();
        if ($track === null) {
            return $this->metaLine();
        }

        $glyph = $this->paused ? '⏸ ' : '▶ ';
        $clock = self::clock($this->position) . ' / ' . self::durationOrDash($track);

        return $glyph . $track->title . '   ' . $clock;
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

    /** A track's human duration, or "—" when it is unknown (for the now-playing clock). */
    private static function durationOrDash(Track $track): string
    {
        $label = $track->durationLabel();

        return $label === '' ? '—' : $label;
    }

    /** Seconds → "m:ss" (or "h:mm:ss" past an hour). */
    private static function clock(int $seconds): string
    {
        $s = max(0, $seconds);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
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

    public function playingIndex(): ?int
    {
        return $this->playing;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function position(): int
    {
        return $this->position;
    }

    /** The current heartbeat generation (an armed tick carries this epoch). */
    public function audioEpoch(): int
    {
        return $this->audioEpoch;
    }
}

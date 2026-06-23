<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\Dto\PlaybackMarkers;
use Phlix\Console\Api\Dto\SubtitleTrack;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Config\Config;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\PlaybackMarkersLoadedMsg;
use Phlix\Console\Msg\PlayerPrepareFailedMsg;
use Phlix\Console\Msg\PlayerReadyMsg;
use Phlix\Console\Msg\PlayNextMsg;
use Phlix\Console\Msg\ProgressTickMsg;
use Phlix\Console\Msg\ResumeInfoMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\SessionStartedMsg;
use Phlix\Console\Msg\SiblingsLoadedMsg;
use Phlix\Console\Msg\SubtitleVttLoadedMsg;
use Phlix\Console\Msg\UpNextTickMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Scrubber;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Reel\Msg\TickMsg as ReelTickMsg;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Render\RendererFactory;
use SugarCraft\Sprinkles\Style;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * The in-terminal video player — the centrepiece of Phase 4.
 *
 * It hosts a sugar-reel {@see Player} (itself a TEA model) and **direct-plays**
 * the item's signed `stream_url` by feeding it straight to ffmpeg: ffmpeg
 * decodes HEVC/MKV/AV1 natively, so the console plays containers the browser
 * cannot and bypasses the server's HLS transcode entirely. The signed URL needs
 * no auth header (it carries its own `?exp=&sig=`), so it is fed verbatim.
 *
 * The inner player is built off the synchronous path (its probe + decoder spawn
 * happen inside the init Cmd, after "Preparing…" renders) and delivered via
 * {@see PlayerReadyMsg}; playback then auto-starts. The screen pumps the inner
 * player's frames by forwarding its tick to it, intercepts the keys it
 * overrides (Esc/q → back with teardown; ←/→ → time-based ±10s seek), and
 * forwards the rest (Space/[/]/m/digits) to the inner player unchanged.
 *
 * Stable collaborators are readonly; mutable view state is private and copied
 * via clone-mutate (the established screen idiom). The screen is
 * {@see Teardownable} so leaving it — by Esc, or a global Ctrl-C quit — stops
 * the ffmpeg/ffplay subprocesses rather than leaking them.
 */
final class PlayerScreen implements Model, Teardownable
{
    use SubscriptionCapable;

    /** Rows reserved below the video: caption + scrubber + status line + 1 slack for the inner player's own status line. */
    private const CHROME_ROWS = 4;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    /** Jellyfin-style 100ns ticks per second (the server's progress unit). */
    private const TICKS_PER_SECOND = 10_000_000;
    /** Seconds between throttled progress reports. */
    private const PROGRESS_INTERVAL = 10.0;
    /** Below this, a saved position isn't worth resuming from. */
    private const RESUME_FLOOR = 5.0;
    /** Hide the "Resumed from …" hint once the user is this far past the resume point. */
    private const RESUME_HINT_WINDOW = 45.0;
    /** Seconds the up-next card counts down before auto-advancing. */
    private const UP_NEXT_COUNTDOWN = 8;

    private ?Player $inner = null;
    private ?string $error = null;
    private bool $chromeHidden = false;
    private bool $tornDown = false;
    private ?PlaybackMarkers $markers = null;
    private ?string $sessionId = null;
    private ?float $resumeSeconds = null;
    private bool $resumeApplied = false;
    /** The season's episodes (the up-next queue), or null for a non-episode / no queue. */
    private ?array $siblings = null;
    private int $currentIndex = -1;
    /** Remaining seconds on the end-of-episode up-next countdown, or null when inactive. */
    private ?int $upNext = null;
    /** The parsed caption track, once fetched (null = none / not yet loaded). */
    private ?\SugarCraft\Reel\Subtitle\WebVtt $captions = null;
    private bool $captionsOn = false;
    /** True once a caption fetch has been kicked off (so `c` doesn't refetch). */
    private bool $captionsFetched = false;

    /**
     * @param \Closure(string $url, int $cols, int $rows): Player $playerFactory
     *        Builds the inner sugar-reel player (injected so tests use a
     *        fake-decoder-backed player instead of spawning real ffmpeg).
     */
    public function __construct(
        private readonly MediaItem $item,
        private readonly string $baseUrl,
        private readonly ApiClient $api,
        private readonly \Closure $playerFactory,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    /**
     * The real factory: probe the stream and open an ffmpeg-backed player at the
     * best render mode the terminal supports.
     *
     * @return \Closure(string $url, int $cols, int $rows): Player
     */
    public static function productionFactory(): \Closure
    {
        return static fn (string $url, int $cols, int $rows): Player
            => Player::open($url, $cols, $rows, null, RendererFactory::autoMode(), false, 'standard');
    }

    public function init(): ?\Closure
    {
        // Build the player and fetch its markers + resume + episode queue concurrently.
        $cmds = [
            Cmd::promise(fn (): PromiseInterface => $this->buildPlayer()),
            $this->fetchMarkers(),
            $this->fetchResume(),
        ];
        $siblings = $this->fetchSiblings();
        if ($siblings !== null) {
            $cmds[] = $siblings;
        }

        return Cmd::batch(...$cmds);
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return $this->onResize($msg->cols, $msg->rows);
        }
        if ($msg instanceof PlayerReadyMsg) {
            return $this->onReady($msg->player);
        }
        if ($msg instanceof PlayerPrepareFailedMsg) {
            return [$this->withError($msg->reason), null];
        }
        if ($msg instanceof PlaybackMarkersLoadedMsg) {
            $next = clone $this;
            $next->markers = $msg->markers;

            return [$next, null];
        }
        if ($msg instanceof ResumeInfoMsg) {
            return $this->onResumeInfo($msg->seconds);
        }
        if ($msg instanceof SiblingsLoadedMsg) {
            $next = clone $this;
            $next->siblings = $msg->siblings;
            $next->currentIndex = $msg->currentIndex;

            return [$next, null];
        }
        if ($msg instanceof UpNextTickMsg) {
            return $this->onUpNextTick();
        }
        if ($msg instanceof SubtitleVttLoadedMsg) {
            $next = clone $this;
            $next->captions = $msg->captions;
            if ($msg->captions === null) {
                $next->captionsOn = false; // nothing to show
            }

            return [$next, null];
        }
        if ($msg instanceof SessionStartedMsg) {
            return $this->onSessionStarted($msg->sessionId);
        }
        if ($msg instanceof ProgressTickMsg) {
            return $this->onProgressTick();
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof ReelTickMsg) {
            // The frame pump: drive the inner player's wall-clock frame advance.
            return $this->forwardToInner($msg);
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->error !== null) {
            return Chrome::frame($this->item->name, "\n  {$this->error}", 'Esc  back', $this->cols, $this->rows);
        }
        if ($this->inner === null) {
            return Chrome::frame($this->item->name, "\n  Preparing playback…", 'Esc  back', $this->cols, $this->rows);
        }

        $frame = $this->inner->view();
        if ($this->chromeHidden) {
            return $frame;
        }

        return $frame
            . "\n" . $this->captionLine($this->inner)
            . "\n" . $this->scrubberLine($this->inner)
            . "\n" . $this->statusLine($this->inner);
    }

    // ---- lifecycle -----------------------------------------------------

    /**
     * Build the inner player (the probe + decoder spawn happen here, off the
     * synchronous update path). A missing stream URL or a build failure (e.g. no
     * local ffmpeg — the transcode fallback lands in a later step) becomes a
     * {@see PlayerPrepareFailedMsg} rather than a crash.
     *
     * @return PromiseInterface<Msg>
     */
    private function buildPlayer(): PromiseInterface
    {
        $url = $this->streamUrl();
        if ($url === '') {
            return resolve(new PlayerPrepareFailedMsg('This title has no playable source.'));
        }

        try {
            $player = ($this->playerFactory)($url, $this->cols, $this->innerRows());

            return resolve(new PlayerReadyMsg($player));
        } catch (\Throwable $e) {
            return resolve(new PlayerPrepareFailedMsg('Could not start playback: ' . $e->getMessage()));
        }
    }

    /**
     * Fetch the intro/outro markers + chapters (optional — a failure leaves the
     * scrubber plain; only an auth failure surfaces, as a session expiry, so the
     * App can re-authenticate and tear this player down).
     */
    private function fetchMarkers(): \Closure
    {
        $id = $this->item->id;

        return Cmd::promise(fn (): PromiseInterface => $this->api->playbackMarkers($id)->then(
            static fn (PlaybackMarkers $markers): Msg => new PlaybackMarkersLoadedMsg($markers),
            static fn (\Throwable $e): ?Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : null,
        ));
    }

    /** Skip the intro/outro segment under the playhead by seeking to its end. */
    private function skipMarker(): array
    {
        $inner = $this->inner;
        if ($inner === null || $this->markers === null) {
            return [$this, null];
        }
        $skip = $this->markers->activeSkip($inner->position());
        if ($skip === null) {
            return [$this, null];
        }

        $wasEnded = $inner->ended;
        $seeked = $inner->seekToSeconds($skip->end);

        $next = clone $this;
        $next->inner = $seeked;

        return [$next, $wasEnded ? $this->tickCmd($seeked) : null];
    }

    /** Restart playback from the beginning and dismiss the resume hint. */
    private function startOver(): array
    {
        $inner = $this->inner;
        if ($inner === null) {
            return [$this, null];
        }

        $wasEnded = $inner->ended;
        $seeked = $inner->seekToSeconds(0.0);

        $next = clone $this;
        $next->inner = $seeked;
        $next->resumeSeconds = null; // hide the "Resumed from …" hint

        return [$next, $wasEnded ? $this->tickCmd($seeked) : null];
    }

    // ---- resume --------------------------------------------------------

    /**
     * Look up this item's saved position in continue-watching → ResumeInfoMsg
     * (null when absent / near-complete / on any error — best-effort).
     */
    private function fetchResume(): \Closure
    {
        $id = $this->item->id;

        return Cmd::promise(fn (): PromiseInterface => $this->api->continueWatching()->then(
            static function (array $items) use ($id): Msg {
                foreach ($items as $cw) {
                    if ($cw->item->id === $id && $cw->positionTicks > 0 && $cw->progress() < 0.95) {
                        return new ResumeInfoMsg($cw->positionTicks / self::TICKS_PER_SECOND);
                    }
                }

                return new ResumeInfoMsg(null);
            },
            static fn (\Throwable $e): Msg => new ResumeInfoMsg(null),
        ));
    }

    // ---- up-next (episode queue) ---------------------------------------

    /**
     * Fetch the season's episodes (the up-next queue) for an episode item, or
     * null when this isn't an episode with a parent season. Best-effort.
     */
    private function fetchSiblings(): ?\Closure
    {
        if ($this->item->type !== 'episode' || $this->item->parentId === null) {
            return null;
        }
        $parentId = $this->item->parentId;
        $currentId = $this->item->id;

        return Cmd::promise(fn (): PromiseInterface => $this->api->media(new MediaQuery(parentId: $parentId, limit: 500))->then(
            static function (MediaPage $page) use ($currentId): Msg {
                $index = -1;
                foreach ($page->items as $i => $episode) {
                    if ($episode->id === $currentId) {
                        $index = $i;
                        break;
                    }
                }

                return new SiblingsLoadedMsg($page->items, $index);
            },
            static fn (\Throwable $e): Msg => new SiblingsLoadedMsg([], -1),
        ));
    }

    private function nextItem(): ?MediaItem
    {
        if ($this->siblings === null || $this->currentIndex < 0) {
            return null;
        }

        return $this->siblings[$this->currentIndex + 1] ?? null;
    }

    private function prevItem(): ?MediaItem
    {
        if ($this->siblings === null || $this->currentIndex <= 0) {
            return null;
        }

        return $this->siblings[$this->currentIndex - 1] ?? null;
    }

    /**
     * Leave for another episode: report a final position + end the session +
     * tear down, then ask the App to swap in the next/prev episode's player.
     */
    private function advanceTo(MediaItem $item): array
    {
        $exit = $this->exitReportCmds();
        $this->teardown();
        $exit[] = Cmd::send(new PlayNextMsg($item));

        return [$this, Cmd::batch(...$exit)];
    }

    private function onUpNextTick(): array
    {
        // Cancelled — the viewer scrubbed back out of the ended state (or it was
        // never really active). Stop counting.
        if ($this->upNext === null || $this->inner === null || !$this->inner->ended) {
            $next = clone $this;
            $next->upNext = null;

            return [$next, null];
        }

        $remaining = $this->upNext - 1;
        if ($remaining <= 0) {
            $item = $this->nextItem();
            if ($item !== null) {
                return $this->advanceTo($item);
            }
            $next = clone $this;
            $next->upNext = null;

            return [$next, null];
        }

        $next = clone $this;
        $next->upNext = $remaining;

        return [$next, $this->upNextTickCmd()];
    }

    private function upNextTickCmd(): \Closure
    {
        return Cmd::tick(1.0, static fn (): Msg => new UpNextTickMsg());
    }

    // ---- captions ------------------------------------------------------

    /**
     * Toggle captions. The track is fetched lazily on first enable (tracks →
     * default track → WebVTT, in one Cmd); afterwards `c` just flips visibility.
     */
    private function toggleCaptions(): array
    {
        // Already loaded → flip visibility.
        if ($this->captions !== null) {
            $next = clone $this;
            $next->captionsOn = !$this->captionsOn;

            return [$next, null];
        }
        // Fetched once and found nothing → nothing to toggle.
        if ($this->captionsFetched) {
            return [$this, null];
        }

        // First enable: optimistically on, kick off the fetch.
        $next = clone $this;
        $next->captionsFetched = true;
        $next->captionsOn = true;

        return [$next, $this->fetchCaptionsCmd()];
    }

    /**
     * Fetch the subtitle tracks, pick the default (or first), fetch + parse its
     * WebVTT → SubtitleVttLoadedMsg(?WebVtt). Any failure / no track → null.
     */
    private function fetchCaptionsCmd(): \Closure
    {
        $id = $this->item->id;

        return Cmd::promise(fn (): PromiseInterface => $this->api->subtitleTracks($id)->then(
            function (array $tracks) use ($id): PromiseInterface {
                $track = $this->pickDefaultTrack($tracks);
                if ($track === null) {
                    return resolve(new SubtitleVttLoadedMsg(null));
                }

                return $this->api->subtitleVtt($id, $track->index)->then(
                    static fn (string $vtt): Msg => new SubtitleVttLoadedMsg(\SugarCraft\Reel\Subtitle\WebVtt::parse($vtt)),
                    static fn (\Throwable $e): Msg => new SubtitleVttLoadedMsg(null),
                );
            },
            static fn (\Throwable $e): Msg => new SubtitleVttLoadedMsg(null),
        ));
    }

    /**
     * The default track (or the first) from a track list, or null when empty.
     *
     * @param list<SubtitleTrack> $tracks
     */
    private function pickDefaultTrack(array $tracks): ?SubtitleTrack
    {
        foreach ($tracks as $track) {
            if ($track->default) {
                return $track;
            }
        }

        return $tracks[0] ?? null;
    }

    // ---- progress reporting --------------------------------------------

    /** Open a playback session; on success → SessionStartedMsg. Failure is swallowed. */
    private function createSessionCmd(): \Closure
    {
        return Cmd::promise(fn (): PromiseInterface => $this->api->createSession(Config::deviceId())->then(
            static fn (string $sessionId): ?Msg => $sessionId !== '' ? new SessionStartedMsg($sessionId) : null,
            // Progress reporting is best-effort — a failed session never interrupts playback.
            static fn (\Throwable $e): ?Msg => null,
        ));
    }

    private function onSessionStarted(string $sessionId): array
    {
        $next = clone $this;
        $next->sessionId = $sessionId;

        // Begin the throttled progress heartbeat.
        return [$next, $this->progressTickCmd()];
    }

    private function onProgressTick(): array
    {
        // Report the current position (if still reportable) and re-arm the heartbeat.
        $cmds = [$this->progressTickCmd()];
        $report = $this->reportProgressCmd();
        if ($report !== null) {
            $cmds[] = $report;
        }

        return [$this, Cmd::batch(...$cmds)];
    }

    private function progressTickCmd(): \Closure
    {
        return Cmd::tick(self::PROGRESS_INTERVAL, static fn (): Msg => new ProgressTickMsg());
    }

    /** A best-effort progress POST for the current position, or null if not reportable. */
    private function reportProgressCmd(): ?\Closure
    {
        $inner = $this->inner;
        $sessionId = $this->sessionId;
        if ($inner === null || $sessionId === null) {
            return null;
        }

        $positionTicks = (int) round($inner->position() * self::TICKS_PER_SECOND);
        $durationTicks = (int) round($inner->duration() * self::TICKS_PER_SECOND);
        $isPaused = $inner->paused;
        $mediaId = $this->item->id;

        return Cmd::promise(fn (): PromiseInterface => $this->api->reportProgress($sessionId, $mediaId, $positionTicks, $durationTicks, $isPaused)->then(
            static fn (bool $ok): ?Msg => null,
            static fn (\Throwable $e): ?Msg => null, // never interrupt playback on a telemetry error
        ));
    }

    /**
     * Exit-time Cmds: a final progress report then end the session (both
     * best-effort). Empty when no session was ever opened.
     *
     * @return list<\Closure>
     */
    private function exitReportCmds(): array
    {
        $sessionId = $this->sessionId;
        if ($sessionId === null) {
            return [];
        }

        $cmds = [];
        $report = $this->reportProgressCmd();
        if ($report !== null) {
            $cmds[] = $report;
        }
        $cmds[] = Cmd::promise(fn (): PromiseInterface => $this->api->endSession($sessionId)->then(
            static fn (bool $ok): ?Msg => null,
            static fn (\Throwable $e): ?Msg => null,
        ));

        return $cmds;
    }

    private function onReady(Player $player): array
    {
        // Auto-play: Space toggles the inner player out of its paused initial
        // state — it starts audio and arms the tick chain. The returned Cmd is
        // that first tick, which flows back up to drive the frame pump. Alongside
        // it, open a playback session so progress can be reported.
        [$started, $tick] = $player->update(new KeyMsg(KeyType::Space));

        $next = clone $this;
        $next->inner = $started instanceof Player ? $started : $player;
        // If the resume position resolved before the player was ready, apply it now.
        $next->applyResumeInPlace();

        return [$next, Cmd::batch($tick, $this->createSessionCmd())];
    }

    /**
     * The saved resume position arrived. Seek the (playing) video to it once,
     * unless it's trivial or the player isn't ready yet (then onReady applies it).
     */
    private function onResumeInfo(?float $seconds): array
    {
        $next = clone $this;
        $next->resumeSeconds = $seconds;
        $next->applyResumeInPlace();

        return [$next, null];
    }

    /**
     * Seek the player to the saved resume position if one is known, the player
     * is ready, and we haven't already resumed. Operates on the current
     * instance — only ever called on a freshly cloned screen.
     */
    private function applyResumeInPlace(): void
    {
        if ($this->inner === null || $this->resumeApplied
            || $this->resumeSeconds === null || $this->resumeSeconds < self::RESUME_FLOOR) {
            return;
        }
        $this->inner = $this->inner->seekToSeconds($this->resumeSeconds);
        $this->resumeApplied = true;
    }

    public function teardown(): void
    {
        if ($this->tornDown) {
            return;
        }
        $this->tornDown = true;
        $this->inner?->stop();
    }

    // ---- input ---------------------------------------------------------

    private function handleKey(KeyMsg $msg): array
    {
        // Esc / q → report a final position, end the session, tear down the
        // subprocesses, and pop back to the detail screen. (Intercepted BEFORE
        // forwarding so the inner player's own quit — which would Cmd::quit the
        // whole app — never fires.) The exit Cmds are captured from the live
        // player before teardown stops it.
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))) {
            $exit = $this->exitReportCmds();
            $this->teardown();
            if ($exit === []) {
                return [$this, Cmd::send(new NavigateBackMsg())];
            }
            $exit[] = Cmd::send(new NavigateBackMsg());

            return [$this, Cmd::batch(...$exit)];
        }

        if ($this->inner === null) {
            return [$this, null]; // transport keys do nothing until the player is ready
        }

        // f → toggle the transport chrome for an immersive, video-only view.
        if ($msg->type === KeyType::Char && ($msg->rune === 'f' || $msg->rune === 'F')) {
            $next = clone $this;
            $next->chromeHidden = !$this->chromeHidden;

            return [$next, null];
        }

        // ← / → → time-based ±10s seek (overrides the inner player's frame seek).
        if ($msg->type === KeyType::Left) {
            return $this->seekBy(-10.0);
        }
        if ($msg->type === KeyType::Right) {
            return $this->seekBy(10.0);
        }

        // s → skip the intro/outro segment under the playhead (if any).
        if ($msg->type === KeyType::Char && ($msg->rune === 's' || $msg->rune === 'S')) {
            return $this->skipMarker();
        }

        // o → start over from the beginning (offered after an auto-resume).
        if ($msg->type === KeyType::Char && ($msg->rune === 'o' || $msg->rune === 'O')) {
            return $this->startOver();
        }

        // c → toggle captions (lazily fetching the track on first enable).
        if ($msg->type === KeyType::Char && ($msg->rune === 'c' || $msg->rune === 'C')) {
            return $this->toggleCaptions();
        }

        // n / p → next / previous episode (manual binge nav).
        if ($msg->type === KeyType::Char && ($msg->rune === 'n' || $msg->rune === 'N')) {
            $next = $this->nextItem();

            return $next !== null ? $this->advanceTo($next) : [$this, null];
        }
        if ($msg->type === KeyType::Char && ($msg->rune === 'p' || $msg->rune === 'P')) {
            $prev = $this->prevItem();

            return $prev !== null ? $this->advanceTo($prev) : [$this, null];
        }

        // Everything else the inner player handles (Space, [ , ] , m, 0–9) → forward.
        return $this->forwardToInner($msg);
    }

    /** Seek the inner player by $delta seconds (time domain), re-arming the tick if it had ended. */
    private function seekBy(float $delta): array
    {
        $inner = $this->inner;
        if ($inner === null) {
            return [$this, null];
        }

        // An ended player has stopped ticking; a normally-playing one has a tick
        // already in flight (so seeking needs no new Cmd), and a paused one stays
        // paused (no tick). Only the ended→seek case must re-arm the chain.
        $wasEnded = $inner->ended;
        $seeked = $inner->seekToSeconds($inner->position() + $delta);

        $next = clone $this;
        $next->inner = $seeked;

        return [$next, $wasEnded ? $this->tickCmd($seeked) : null];
    }

    private function forwardToInner(Msg $msg): array
    {
        $inner = $this->inner;
        if ($inner === null) {
            return [$this, null];
        }

        [$updated, $cmd] = $inner->update($msg);
        $nextInner = $updated instanceof Player ? $updated : $inner;

        $next = clone $this;
        $next->inner = $nextInner;

        // The episode just ended → start the up-next countdown if there's a next one.
        if ($nextInner->ended && !$inner->ended && $this->upNext === null && $this->nextItem() !== null) {
            $next->upNext = self::UP_NEXT_COUNTDOWN;

            return [$next, $this->upNextTickCmd()];
        }

        return [$next, $cmd];
    }

    private function onResize(int $cols, int $rows): array
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        if ($this->inner !== null) {
            [$nextInner, $cmd] = $this->inner->update(new WindowSizeMsg($cols, $next->innerRows()));
            $next->inner = $nextInner instanceof Player ? $nextInner : $this->inner;

            return [$next, $cmd];
        }

        return [$next, null];
    }

    // ---- rendering -----------------------------------------------------

    /** The active caption, centered, while captions are on — else a blank line. */
    private function captionLine(Player $inner): string
    {
        if (!$this->captionsOn || $this->captions === null) {
            return '';
        }
        $text = $this->captions->cueAt($inner->position());
        if ($text === null || $text === '') {
            return '';
        }

        $text = Width::truncate(str_replace("\n", '  ', $text), max(1, $this->cols - 2));
        $pad = max(0, intdiv($this->cols - Width::of($text), 2));

        return str_repeat(' ', $pad) . Style::new()->bold()->render($text);
    }

    /** The progress bar with chapter ticks. */
    private function scrubberLine(Player $inner): string
    {
        return Scrubber::of($inner->position(), $inner->duration(), $this->cols, $this->chapters())->render();
    }

    /** play/pause glyph + title + a contextual resume/skip prompt + the key hints. */
    private function statusLine(Player $inner): string
    {
        // End of episode: the up-next countdown replaces the normal status line
        // (only while actually ended — scrubbing back reveals the normal line).
        if ($this->upNext !== null && $inner->ended) {
            return $this->upNextLine();
        }

        $glyph = $inner->paused ? '⏸' : '▶';
        // A live resume hint wins over the skip prompt (resume usually clears the intro).
        $prompt = $this->resumeHint($inner) ?? $this->skipPrompt($inner);
        $nav = $this->nextItem() !== null ? '  n next' : '';
        $cc = $this->captionsOn ? '  c cc✓' : '  c cc';
        $hint = 'Space ⏯  ←→ ±10s  [ ] speed  m mode' . $cc . $nav . '  q back';

        $title = Width::truncate($this->item->name, max(8, $this->cols - 60));
        $line = sprintf('%s %s%s   ·   %s', $glyph, $title, $prompt, $hint);

        return Style::new()->bold()->render(Width::truncate($line, max(1, $this->cols)));
    }

    /** "▶ Up next: S01E03 The Title · starting in 7…   n play now   Esc back". */
    private function upNextLine(): string
    {
        $next = $this->nextItem();
        $title = $next !== null ? $this->episodeLabel($next) : '';
        $line = sprintf('▶ Up next: %s · starting in %d…   n play now   Esc back', $title, $this->upNext ?? 0);

        return Style::new()->bold()->render(Width::truncate($line, max(1, $this->cols)));
    }

    /** "S01E03 The Title" for an episode, else its name. */
    private function episodeLabel(MediaItem $episode): string
    {
        if ($episode->seasonNumber !== null && $episode->episodeNumber !== null) {
            $code = sprintf('S%02dE%02d', $episode->seasonNumber, $episode->episodeNumber);
            $name = ($episode->episodeTitle !== null && $episode->episodeTitle !== '') ? $episode->episodeTitle : $episode->name;

            return $name !== '' ? "{$code} {$name}" : $code;
        }

        return $episode->name;
    }

    /** "   s Skip Intro/Outro" when the playhead is in a skip window, else ''. */
    private function skipPrompt(Player $inner): string
    {
        $skip = $this->markers?->skipLabel($inner->position());

        return $skip !== null ? "   s {$skip}" : '';
    }

    /** "   ↺ Resumed from m:ss · o start over" for a while after an auto-resume, else ''. */
    private function resumeHint(Player $inner): ?string
    {
        if (!$this->resumeApplied || $this->resumeSeconds === null) {
            return null;
        }
        // Dismiss once the viewer is well past the resume point.
        if ($inner->position() > $this->resumeSeconds + self::RESUME_HINT_WINDOW) {
            return null;
        }

        return '   ↺ Resumed from ' . self::clock($this->resumeSeconds) . ' · o start over';
    }

    /** Seconds → "m:ss" (or "h:mm:ss" past an hour). */
    private static function clock(float $seconds): string
    {
        $s = max(0, (int) round($seconds));
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }

    /** @return list<\Phlix\Console\Api\Dto\Chapter> */
    private function chapters(): array
    {
        return $this->markers?->chapters ?? [];
    }

    /** Resolve the item's signed stream URL against the server base (handles a relative path). */
    private function streamUrl(): string
    {
        $url = $this->item->streamUrl ?? '';
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private function innerRows(): int
    {
        return max(4, $this->rows - self::CHROME_ROWS);
    }

    private function tickCmd(Player $player): \Closure
    {
        $fps = $player->fps > 0.0 ? $player->fps : 24.0;

        return Cmd::tick(1.0 / $fps, static fn (): Msg => new ReelTickMsg());
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function isReady(): bool
    {
        return $this->inner !== null && $this->error === null;
    }

    public function isPlaying(): bool
    {
        return $this->inner !== null && !$this->inner->paused;
    }

    public function position(): float
    {
        return $this->inner?->position() ?? 0.0;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function isChromeHidden(): bool
    {
        return $this->chromeHidden;
    }

    public function player(): ?Player
    {
        return $this->inner;
    }

    public function markers(): ?PlaybackMarkers
    {
        return $this->markers;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function resumeSeconds(): ?float
    {
        return $this->resumeSeconds;
    }

    public function isResumed(): bool
    {
        return $this->resumeApplied;
    }

    public function upNextCountdown(): ?int
    {
        return $this->upNext;
    }

    public function hasNext(): bool
    {
        return $this->nextItem() !== null;
    }

    public function hasPrev(): bool
    {
        return $this->prevItem() !== null;
    }

    public function captionsOn(): bool
    {
        return $this->captionsOn;
    }

    public function hasCaptions(): bool
    {
        return $this->captions !== null;
    }
}

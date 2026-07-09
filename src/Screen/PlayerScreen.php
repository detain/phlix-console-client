<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\Dto\PlaybackMarkers;
use Phlix\Console\Api\Dto\Rendition;
use Phlix\Console\Api\Dto\StreamAudioTrack;
use Phlix\Console\Api\Dto\StreamSubtitleTrack;
use Phlix\Console\Api\Dto\SubtitleTrack;
use Phlix\Console\Api\Dto\SyncPlayPlaybackCommand;
use Phlix\Console\Api\Dto\SyncPlayRoom;
use Phlix\Console\Api\Dto\SyncPlayUser;
use Phlix\Console\Api\Dto\TranscodeJob;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Api\SyncPlay\SyncPlayService;
use Phlix\Console\App;
use Phlix\Console\Config\Config;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenRecommendationsMsg;
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
use Phlix\Console\Msg\SyncPlayFailedMsg;
use Phlix\Console\Msg\SyncPlayJoinedMsg;
use Phlix\Console\Msg\SyncPlayLeftMsg;
use Phlix\Console\Msg\SyncPlayMemberJoinedMsg;
use Phlix\Console\Msg\SyncPlayMemberLeftMsg;
use Phlix\Console\Msg\SyncPlayPlaybackCommandMsg;
use Phlix\Console\Msg\SyncPlayRoomsLoadedMsg;
use Phlix\Console\Msg\TranscodePollMsg;
use Phlix\Console\Msg\TranscodeStartedMsg;
use Phlix\Console\Msg\TranscodeStatusMsg;
use Phlix\Console\Msg\UpNextTickMsg;
use Phlix\Console\Ui\AudioTrackList;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\QualityMenu;
use Phlix\Console\Ui\SubtitleTrackList;
use Phlix\Console\Ui\Scrubber;
use Phlix\Console\Ui\SyncPlayModal;
use Phlix\Console\Ui\SyncPlayOverlay;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Reel\Msg\TickMsg as ReelTickMsg;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Render\Mode;
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
final class PlayerScreen implements Model, Teardownable, CapturesSlash, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

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
    /** Seconds between transcode-readiness polls. */
    private const TRANSCODE_POLL_INTERVAL = 2.0;

    private ?Player $inner = null;
    private ?string $error = null;
    private bool $chromeHidden = false;
    private bool $tornDown = false;
    private ?PlaybackMarkers $markers = null;
    private ?string $sessionId = null;
    /**
     * Set the moment {@see createSessionCmd} is FIRST issued (synchronously, in
     * the same `onReady` that returns the Cmd) — NOT once `sessionId` itself
     * arrives. `sessionId` only lands asynchronously once the `createSession`
     * HTTP round-trip completes (via {@see onSessionStarted}); guarding the
     * "only one session" check on `sessionId` would race a quality switch that
     * lands (a second `onReady`) before that round-trip finishes — `sessionId`
     * would still be null, so a second `createSessionCmd` would fire, the first
     * session id would be overwritten, and the original session would never be
     * `endSession`'d (orphaned server-side). Guarding on this synchronous flag
     * instead makes the "request the session at most once" decision race-free.
     */
    private bool $sessionRequested = false;
    private ?float $resumeSeconds = null;
    private bool $resumeApplied = false;
    /**
     * The season's episodes (the up-next queue), or null for a non-episode / no queue.
     *
     * @var list<MediaItem>|null
     */
    private ?array $siblings = null;
    private int $currentIndex = -1;
    /** Remaining seconds on the end-of-episode up-next countdown, or null when inactive. */
    private ?int $upNext = null;
    /** The parsed caption track, once fetched (null = none / not yet loaded). */
    private ?\SugarCraft\Reel\Subtitle\WebVtt $captions = null;
    private bool $captionsOn = false;
    /** True once a caption fetch has been kicked off (so `c` doesn't refetch). */
    private bool $captionsFetched = false;
    /** Transcode fallback (when direct-play fails): tried once, the job id + progress. */
    private bool $transcodeTried = false;
    private bool $transcoding = false;
    private ?string $transcodeJob = null;
    private ?float $transcodeProgress = null;
    /**
     * The ABR ladder rungs the active transcode exposes (highest-first), or []
     * for a direct-played / legacy item with no per-rung choice.
     *
     * Populated ONLY from a {@see TranscodeJob}'s `variants` (see
     * {@see rememberLadder}). The server also exposes a pre-flight ladder
     * preview on `GET /api/v1/media/{id}/playback-info`, but the console does
     * NOT fetch or wire it in: every rung in that preview has a `null` `url`
     * (no job exists yet, so there's nothing to pin to), so it cannot drive an
     * actual quality switch and would only add unusable placeholder rows before
     * direct-play has even been attempted. The picker only becomes meaningful
     * once a real transcode job exists and hands back signed per-variant
     * playlist URLs — a deliberate scope decision, not an oversight.
     *
     * @var list<Rendition>
     */
    private array $variants = [];
    /** The signed master (multi-variant) playlist URL — the target for the "Auto" pin. */
    private ?string $transcodeMasterUrl = null;
    /** The pinned rendition id, or null for "Auto" (server-driven ABR / the master). */
    private ?string $selectedQuality = null;
    /** The open quality picker overlay, or null when closed. */
    private ?QualityMenu $qualityMenu = null;
    /** The available audio tracks from playback-info. */
    /** @var list<StreamAudioTrack> */
    private array $audioTracks = [];
    /** The available subtitle tracks from playback-info. */
    /** @var list<StreamSubtitleTrack> */
    private array $subtitleTracks = [];
    /** The selected audio track id, or null for the first track. */
    private ?string $selectedAudioTrack = null;
    /** The selected subtitle track id, or null for "off". */
    private ?string $selectedSubtitleTrack = null;
    /** The open audio track picker overlay, or null when closed. */
    private ?AudioTrackList $audioTrackMenu = null;
    /** The open subtitle track picker overlay, or null when closed. */
    private ?SubtitleTrackList $subtitleTrackMenu = null;
    /** A one-shot seek (seconds) to re-apply once a quality-switch rebuild becomes ready. */
    private ?float $pendingSeek = null;
    /** The SyncPlay service for group playback sync, or null when not available. */
    private ?SyncPlayService $syncPlayService = null;
    /** The open SyncPlay modal, or null when closed. */
    private ?SyncPlayModal $syncPlayModal = null;
    /** The current SyncPlay room name for overlay display, or null when not in a room. */
    private ?string $syncPlayRoomName = null;
    /** The current SyncPlay member count for overlay display. */
    private int $syncPlayMemberCount = 0;
    /** The current SyncPlay sync status for overlay display. */
    private string $syncPlayStatus = 'Not in room';

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
        ?SyncPlayService $syncPlayService = null,
    ) {
        $this->syncPlayService = $syncPlayService;
    }

    /**
     * The real factory: probe the stream and open an ffmpeg-backed player.
     *
     * @param string|null $mode candy-mosaic protocol the app is rendering with
     *                          ('sixel'/'halfblock'/'quarterblock'/…). The player
     *                          opens in the matching {@see Mode} so video plays in
     *                          the same mode as the posters; an unknown/`null`
     *                          mode (e.g. 'auto' → 'chafa') falls back to the
     *                          terminal's best auto-detected mode.
     * @param array{cellWidth:int,cellHeight:int}|null $cellSize The terminal's detected
     *                          cell pixel size. Graphics modes decode video at the full
     *                          pixel resolution (cells × cell-pixel-size); null falls back
     *                          to a 10×20 assumed cell box.
     * @return \Closure(string $url, int $cols, int $rows): Player
     */
    public static function productionFactory(?string $mode = null, ?array $cellSize = null): \Closure
    {
        $reelMode = ($mode !== null ? Mode::tryFrom($mode) : null) ?? RendererFactory::autoMode();
        $cellPxW = $cellSize['cellWidth'] ?? 10;
        $cellPxH = $cellSize['cellHeight'] ?? 20;

        return static fn (string $url, int $cols, int $rows): Player
            => Player::open($url, $cols, $rows, null, $reelMode, false, 'standard', $cellPxW, $cellPxH);
    }

    public function init(): \Closure
    {
        // Build the player and fetch its markers + resume + episode queue concurrently.
        $cmds = [
            $this->buildPlayerCmd($this->streamUrl()),
            $this->fetchMarkers(),
            $this->fetchResume(),
        ];
        $siblings = $this->fetchSiblings();
        if ($siblings !== null) {
            $cmds[] = $siblings;
        }

        return Cmd::batch(...$cmds);
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return $this->onResize($msg->cols, $msg->rows);
        }
        if ($msg instanceof PlayerReadyMsg) {
            return $this->onReady($msg->player);
        }
        if ($msg instanceof PlayerPrepareFailedMsg) {
            return $this->onPrepareFailed($msg->reason);
        }
        if ($msg instanceof TranscodeStartedMsg) {
            return $this->onTranscodeStarted($msg->job);
        }
        if ($msg instanceof TranscodePollMsg) {
            return $this->onTranscodePoll();
        }
        if ($msg instanceof TranscodeStatusMsg) {
            return $this->onTranscodeStatus($msg->job);
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
        if ($msg instanceof SyncPlayRoomsLoadedMsg) {
            return $this->onSyncPlayRoomsLoaded($msg->rooms);
        }
        if ($msg instanceof SyncPlayJoinedMsg) {
            return $this->onSyncPlayJoined($msg->room);
        }
        if ($msg instanceof SyncPlayLeftMsg) {
            return $this->onSyncPlayLeft();
        }
        if ($msg instanceof SyncPlayFailedMsg) {
            return $this->onSyncPlayFailed($msg->reason);
        }
        if ($msg instanceof SyncPlayMemberJoinedMsg) {
            return $this->onSyncPlayMemberJoined($msg->member);
        }
        if ($msg instanceof SyncPlayMemberLeftMsg) {
            return $this->onSyncPlayMemberLeft($msg->memberId);
        }
        if ($msg instanceof SyncPlayPlaybackCommandMsg) {
            return $this->onSyncPlayPlaybackCommand($msg->command);
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
        $base = $this->renderBase();
        // The audio track picker dims + centres its box over the current frame.
        if ($this->audioTrackMenu !== null) {
            return $this->audioTrackMenu->render($base);
        }
        // The subtitle track picker dims + centres its box over the current frame.
        if ($this->subtitleTrackMenu !== null) {
            return $this->subtitleTrackMenu->render($base);
        }
        // The quality picker dims + centres its box over the current frame.
        if ($this->qualityMenu !== null) {
            return $this->qualityMenu->render($base);
        }
        // The SyncPlay modal (create/join room).
        if ($this->syncPlayModal !== null) {
            $withOverlay = SyncPlayOverlay::render(
                $base,
                $this->syncPlayRoomName,
                $this->syncPlayMemberCount,
                $this->syncPlayStatus,
                $this->cols,
                $this->rows,
                $this->theme(),
            );

            return $this->syncPlayModal->render($withOverlay);
        }

        // SyncPlay overlay when in a room (but modal not open).
        if ($this->syncPlayService?->isInRoom() === true) {
            return SyncPlayOverlay::render(
                $base,
                $this->syncPlayRoomName,
                $this->syncPlayMemberCount,
                $this->syncPlayStatus,
                $this->cols,
                $this->rows,
                $this->theme(),
            );
        }

        return $base;
    }

    private function renderBase(): string
    {
        if ($this->error !== null) {
            return Chrome::frame($this->item->name, "\n  {$this->error}", 'Esc  back', $this->cols, $this->rows, theme: $this->theme());
        }
        if ($this->inner === null) {
            $body = $this->transcoding
                ? sprintf("\n  Preparing playback (transcoding… %d%%)", (int) round($this->transcodeProgress ?? 0.0))
                : "\n  Preparing playback…";

            return Chrome::frame($this->item->name, $body, 'Esc  back', $this->cols, $this->rows, theme: $this->theme());
        }

        $frame = $this->inner->view();
        if ($this->chromeHidden) {
            return $frame;
        }

        // Pixel-graphics modes (sixel/kitty/iTerm2) emit the whole image as one
        // multi-row escape blob. candy-core's renderer diffs line-by-line — one
        // logical line per "\n" — so appending the chrome with newlines drops it a
        // few rows below the top, right on top of the image (the "progress bar on
        // row 3" bug). Keep the view on a SINGLE logical line and pin the transport
        // chrome to the bottom rows with absolute cursor moves, so it always sits
        // below the image regardless of where the image left the cursor.
        if ($this->inner->mode->isGraphics()) {
            return $frame . $this->graphicsChrome($this->inner);
        }

        return $frame
            . "\n" . $this->captionLine($this->inner)
            . "\n" . $this->scrubberLine($this->inner)
            . "\n" . $this->statusLine($this->inner);
    }

    /**
     * Caption + scrubber + status pinned to the bottom three terminal rows with
     * absolute cursor positioning, each row cleared first. Used for pixel-graphics
     * modes whose image blob is a single (multi-row) logical line — see {@see view()}.
     */
    private function graphicsChrome(Player $inner): string
    {
        $bottom = max(3, $this->rows);

        return Ansi::cursorTo($bottom - 2, 1) . Ansi::eraseLine() . $this->captionLine($inner)
            . Ansi::cursorTo($bottom - 1, 1) . Ansi::eraseLine() . $this->scrubberLine($inner)
            . Ansi::cursorTo($bottom, 1) . Ansi::eraseLine() . $this->statusLine($inner);
    }

    // ---- lifecycle -----------------------------------------------------

    /** A Cmd that builds the inner player from $url (off the synchronous path). */
    private function buildPlayerCmd(string $url): \Closure
    {
        return Cmd::promise(fn (): PromiseInterface => $this->buildPlayer($url));
    }

    /**
     * Build the inner player from a source URL (the probe + decoder spawn happen
     * here, off the synchronous update path). An empty URL or a build failure
     * becomes a {@see PlayerPrepareFailedMsg} (which triggers the transcode
     * fallback) rather than a crash.
     *
     * @return PromiseInterface<Msg>
     */
    private function buildPlayer(string $url): PromiseInterface
    {
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

    /**
     * Skip the intro/outro segment under the playhead by seeking to its end.
     *
     * @return array{self, ?\Closure}
     */
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

    /**
     * Restart playback from the beginning and dismiss the resume hint.
     *
     * @return array{self, ?\Closure}
     */
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
     *
     * @return array{self, ?\Closure}
     */
    private function advanceTo(MediaItem $item): array
    {
        $exit = $this->exitReportCmds();
        $this->teardown();
        $exit[] = Cmd::send(new PlayNextMsg($item));

        return [$this, Cmd::batch(...$exit)];
    }

    /** @return array{self, ?\Closure} */
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
     *
     * @return array{self, ?\Closure}
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

    /** @return array{self, ?\Closure} */
    private function onSessionStarted(string $sessionId): array
    {
        $next = clone $this;
        $next->sessionId = $sessionId;

        // Begin the throttled progress heartbeat.
        return [$next, $this->progressTickCmd()];
    }

    /** @return array{self, ?\Closure} */
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

    // ---- transcode fallback --------------------------------------------

    /**
     * Direct-play failed. The first time, fall back to a server HLS transcode
     * (the file may have a codec/container ffmpeg here can't direct-play);
     * thereafter (or if the transcode path also fails) show the error.
     *
     * @return array{self, ?\Closure}
     */
    private function onPrepareFailed(string $reason): array
    {
        if (!$this->transcodeTried) {
            $next = clone $this;
            $next->transcodeTried = true;
            $next->transcoding = true;

            return [$next, $this->startTranscodeCmd()];
        }

        return [$this->withError($reason), null];
    }

    private function startTranscodeCmd(): \Closure
    {
        $id = $this->item->id;

        return Cmd::promise(fn (): PromiseInterface => $this->api->startTranscode($id)->then(
            static fn (TranscodeJob $job): Msg => new TranscodeStartedMsg($job),
            static fn (\Throwable $e): Msg => new PlayerPrepareFailedMsg('Could not prepare this title for playback.'),
        ));
    }

    /** @return array{self, ?\Closure} */
    private function onTranscodeStarted(TranscodeJob $job): array
    {
        $next = clone $this;
        $next->transcodeJob = $job->jobId;
        $next->transcodeProgress = $job->progress;
        $next->rememberLadder($job);

        // Already playable (a reused/complete job) → build now; else start polling.
        if ($job->isPlayable()) {
            return [$next, $this->buildPlayerCmd($this->resolveUrl($job->masterUrl))];
        }

        return [$next, $this->transcodePollCmd()];
    }

    /**
     * Record the ABR ladder + master URL a transcode response advertised, so the
     * quality picker has real rungs to offer and an "Auto" (master) target. Only
     * overwrites the master when the job actually carried one. Mutates in place —
     * only ever called on a freshly cloned screen.
     *
     * The ONLY source `$this->variants` is ever populated from — see that
     * field's docblock for why the server's pre-flight `/playback-info` ladder
     * preview is deliberately not wired in here too.
     */
    private function rememberLadder(TranscodeJob $job): void
    {
        if ($job->variants !== []) {
            $this->variants = $job->variants;
        }
        if ($job->masterUrl !== '') {
            $this->transcodeMasterUrl = $this->resolveUrl($job->masterUrl);
        }
    }

    /** @return array{self, ?\Closure} */
    private function onTranscodePoll(): array
    {
        $jobId = $this->transcodeJob;
        if ($jobId === null) {
            return [$this, null];
        }

        return [$this, Cmd::promise(fn (): PromiseInterface => $this->api->transcodeStatus($jobId)->then(
            static fn (TranscodeJob $job): Msg => new TranscodeStatusMsg($job),
            static fn (\Throwable $e): Msg => new PlayerPrepareFailedMsg('The transcode failed.'),
        ))];
    }

    /** @return array{self, ?\Closure} */
    private function onTranscodeStatus(TranscodeJob $job): array
    {
        if ($job->isFailed()) {
            return [$this->withError('This title could not be prepared for playback.'), null];
        }

        $next = clone $this;
        $next->transcodeProgress = $job->progress;
        $next->rememberLadder($job);

        if ($job->isPlayable()) {
            return [$next, $this->buildPlayerCmd($this->resolveUrl($job->masterUrl))];
        }

        return [$next, $this->transcodePollCmd()];
    }

    private function transcodePollCmd(): \Closure
    {
        return Cmd::tick(self::TRANSCODE_POLL_INTERVAL, static fn (): Msg => new TranscodePollMsg());
    }

    /** @return array{self, ?\Closure} */
    private function onReady(Player $player): array
    {
        // Auto-play: Space toggles the inner player out of its paused initial
        // state — it starts audio and arms the tick chain. The returned Cmd is
        // that first tick, which flows back up to drive the frame pump. Alongside
        // it, open a playback session so progress can be reported.
        [$started, $tick] = $player->update(new KeyMsg(KeyType::Space));

        $next = clone $this;
        $next->inner = $started instanceof Player ? $started : $player;
        // A mid-playback quality switch rebuilt the player — re-seek to where the
        // viewer was (applied before resume, which is a no-op once resumed).
        if ($next->pendingSeek !== null) {
            $next->inner = $next->inner->seekToSeconds($next->pendingSeek);
            $next->pendingSeek = null;
        }
        // If the resume position resolved before the player was ready, apply it now.
        $next->applyResumeInPlace();

        // Open a playback session only the first time a player becomes ready; a
        // quality switch reuses the live session rather than spawning a duplicate.
        // Guarded on the SYNCHRONOUS $sessionRequested flag, not the async
        // $sessionId (see its docblock) — a quality-switch rebuild can become
        // ready again before the first createSession round-trip has resolved.
        if ($this->sessionRequested) {
            return [$next, $tick];
        }
        $next->sessionRequested = true;

        return [$next, Cmd::batch($tick, $this->createSessionCmd())];
    }

    /**
     * The saved resume position arrived. Seek the (playing) video to it once,
     * unless it's trivial or the player isn't ready yet (then onReady applies it).
     *
     * @return array{self, ?\Closure}
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

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // While the SyncPlay modal is open it captures every keystroke.
        if ($this->syncPlayModal !== null) {
            return $this->handleSyncPlayKey($this->syncPlayModal, $msg);
        }

        // While the audio track picker is open it captures every keystroke.
        if ($this->audioTrackMenu !== null) {
            return $this->handleAudioTrackKey($this->audioTrackMenu, $msg);
        }

        // While the subtitle track picker is open it captures every keystroke.
        if ($this->subtitleTrackMenu !== null) {
            return $this->handleSubtitleTrackKey($this->subtitleTrackMenu, $msg);
        }

        // While the quality picker is open it captures every keystroke (like the
        // command palette) — navigate / pick / dismiss it before anything else.
        if ($this->qualityMenu !== null) {
            return $this->handleQualityKey($this->qualityMenu, $msg);
        }

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

        // v → open the quality picker (only when the active transcode exposed rungs).
        if ($msg->type === KeyType::Char && ($msg->rune === 'v' || $msg->rune === 'V')) {
            return $this->openQualityMenu();
        }

        // ← / → → time-based ±10s seek (overrides the inner player's frame seek).
        if ($msg->type === KeyType::Left) {
            return $this->seekBy(-10.0);
        }
        if ($msg->type === KeyType::Right) {
            return $this->seekBy(10.0);
        }

        // A → open the audio track picker (when audio tracks are available).
        if ($msg->type === KeyType::Char && ($msg->rune === 'A' || $msg->rune === 'a')) {
            return $this->openAudioTrackMenu();
        }

        // S → open the subtitle track picker (when subtitle tracks are available).
        if ($msg->type === KeyType::Char && ($msg->rune === 'S')) {
            return $this->openSubtitleTrackMenu();
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

        // W → open the "For You" recommendations screen.
        if ($msg->type === KeyType::Char && ($msg->rune === 'w' || $msg->rune === 'W')) {
            return [$this, Cmd::send(new OpenRecommendationsMsg())];
        }

        // Y → open the SyncPlay modal (create/join room).
        if ($msg->type === KeyType::Char && ($msg->rune === 'y' || $msg->rune === 'Y')) {
            return $this->openSyncPlayModal();
        }

        // Everything else the inner player handles (Space, [ , ] , m, 0–9) → forward.
        return $this->forwardToInner($msg);
    }

    /**
     * Seek the inner player by $delta seconds (time domain), re-arming the tick if it had ended.
     *
     * @return array{self, ?\Closure}
     */
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

    /** @return array{self, ?\Closure} */
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

    // ---- quality picker ------------------------------------------------

    /**
     * Open the quality overlay over the live player. A no-op for a direct-played
     * / legacy item (no ladder to choose from) so the key is harmless there.
     *
     * @return array{self, ?\Closure}
     */
    private function openQualityMenu(): array
    {
        if ($this->variants === []) {
            return [$this, null];
        }

        $next = clone $this;
        $next->qualityMenu = QualityMenu::open($this->variants, $this->selectedQuality, $this->cols, $this->rows);

        return [$next, null];
    }

    /**
     * Drive the open quality overlay: ↑/↓ move, Enter picks, Esc / q dismisses.
     * Any other key is swallowed so it doesn't leak to the player behind it.
     *
     * @return array{self, ?\Closure}
     */
    private function handleQualityKey(QualityMenu $menu, KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))) {
            $next = clone $this;
            $next->qualityMenu = null;

            return [$next, null];
        }
        if ($msg->type === KeyType::Up) {
            $next = clone $this;
            $next->qualityMenu = $menu->up();

            return [$next, null];
        }
        if ($msg->type === KeyType::Down) {
            $next = clone $this;
            $next->qualityMenu = $menu->down();

            return [$next, null];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->applyQualitySelection($menu);
        }

        return [$this, null];
    }

    /**
     * Apply the highlighted quality: rebuild the player from the pinned rung's
     * signed playlist (or, for "Auto", the master multi-variant stream), stopping
     * the old ffmpeg first (no leak) and re-seeking to the current position. When
     * no target URL is known the menu just closes.
     *
     * @return array{self, ?\Closure}
     */
    private function applyQualitySelection(QualityMenu $menu): array
    {
        $rendition = $menu->selectedRendition();
        $url = $rendition?->url;
        $target = ($menu->isAuto() || $url === null || $url === '')
            ? $this->transcodeMasterUrl
            : $this->resolveUrl($url);

        if ($target === null || $target === '') {
            $next = clone $this;
            $next->qualityMenu = null;

            return [$next, null];
        }

        $position = $this->inner?->position();
        $this->inner?->stop(); // stop the old subprocess before spawning the replacement

        $next = clone $this;
        $next->qualityMenu = null;
        $next->selectedQuality = $menu->isAuto() ? null : $rendition?->id;
        $next->inner = null; // show "Preparing…" while the new quality spins up
        $next->pendingSeek = ($position !== null && $position > self::RESUME_FLOOR) ? $position : null;

        return [$next, $this->buildPlayerCmd($target)];
    }

    // ---- audio track picker --------------------------------------------

    /**
     * Open the audio track overlay. A no-op when no audio tracks are available.
     *
     * @return array{self, ?\Closure}
     */
    private function openAudioTrackMenu(): array
    {
        if ($this->audioTracks === []) {
            return [$this, null];
        }

        $next = clone $this;
        $next->audioTrackMenu = AudioTrackList::open($this->audioTracks, $this->selectedAudioTrack, $this->cols, $this->rows);

        return [$next, null];
    }

    /**
     * Drive the open audio track overlay: ↑/↓ move, Enter picks, Esc / q dismisses.
     *
     * @return array{self, ?\Closure}
     */
    private function handleAudioTrackKey(AudioTrackList $menu, KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))) {
            $next = clone $this;
            $next->audioTrackMenu = null;

            return [$next, null];
        }
        if ($msg->type === KeyType::Up) {
            $next = clone $this;
            $next->audioTrackMenu = $menu->up();

            return [$next, null];
        }
        if ($msg->type === KeyType::Down) {
            $next = clone $this;
            $next->audioTrackMenu = $menu->down();

            return [$next, null];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->applyAudioTrackSelection($menu);
        }

        return [$this, null];
    }

    /**
     * Apply the highlighted audio track selection.
     *
     * @return array{self, ?\Closure}
     */
    private function applyAudioTrackSelection(AudioTrackList $menu): array
    {
        $next = clone $this;
        $next->audioTrackMenu = null;
        $next->selectedAudioTrack = $menu->selectedId();

        return [$next, null];
    }

    // ---- subtitle track picker -----------------------------------------

    /**
     * Open the subtitle track overlay. A no-op when no subtitle tracks are available.
     *
     * @return array{self, ?\Closure}
     */
    private function openSubtitleTrackMenu(): array
    {
        if ($this->subtitleTracks === []) {
            return [$this, null];
        }

        $next = clone $this;
        $next->subtitleTrackMenu = SubtitleTrackList::open($this->subtitleTracks, $this->selectedSubtitleTrack, $this->cols, $this->rows);

        return [$next, null];
    }

    /**
     * Drive the open subtitle track overlay: ↑/↓ move, Enter picks, Esc / q dismisses.
     *
     * @return array{self, ?\Closure}
     */
    private function handleSubtitleTrackKey(SubtitleTrackList $menu, KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))) {
            $next = clone $this;
            $next->subtitleTrackMenu = null;

            return [$next, null];
        }
        if ($msg->type === KeyType::Up) {
            $next = clone $this;
            $next->subtitleTrackMenu = $menu->up();

            return [$next, null];
        }
        if ($msg->type === KeyType::Down) {
            $next = clone $this;
            $next->subtitleTrackMenu = $menu->down();

            return [$next, null];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->applySubtitleTrackSelection($menu);
        }

        return [$this, null];
    }

    /**
     * Apply the highlighted subtitle track selection (or "Off").
     *
     * @return array{self, ?\Closure}
     */
    private function applySubtitleTrackSelection(SubtitleTrackList $menu): array
    {
        $next = clone $this;
        $next->subtitleTrackMenu = null;
        $next->selectedSubtitleTrack = $menu->selectedId();
        // When subtitles are turned on (non-null selection), enable captions.
        if (!$menu->isOff()) {
            $next->captionsOn = true;
        }

        return [$next, null];
    }

    // ---- SyncPlay modal -------------------------------------------------

    /**
     * Open the SyncPlay modal, loading public rooms first.
     *
     * @return array{self, ?\Closure}
     */
    private function openSyncPlayModal(): array
    {
        $next = clone $this;
        $next->syncPlayModal = SyncPlayModal::open([], $this->cols, $this->rows);

        // Load public rooms asynchronously - returns a Cmd that resolves to a Msg
        $loadRooms = Cmd::promise(
            fn (): PromiseInterface => $this->api->listSyncPlayRooms()->then(
                static fn (array $rooms): Msg => new SyncPlayRoomsLoadedMsg($rooms),
                static fn (\Throwable $e): Msg => new SyncPlayFailedMsg('Failed to load rooms: ' . $e->getMessage()),
            ),
        );

        return [$next, $loadRooms];
    }

    /**
     * Drive the open SyncPlay modal: ↑/↓ move, Enter picks, Esc / q dismisses.
     *
     * @return array{self, ?\Closure}
     */
    private function handleSyncPlayKey(SyncPlayModal $menu, KeyMsg $msg): array
    {
        // Escape or q → close the modal
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))) {
            // If in creation state (state=1), go back to list; otherwise close
            if ($menu->state() === 1) {
                $next = clone $this;
                $next->syncPlayModal = $menu->cancel();

                return [$next, null];
            }
            $next = clone $this;
            $next->syncPlayModal = null;

            return [$next, null];
        }

        // ← → in creation state: toggle public/private
        if (($msg->type === KeyType::Char && ($msg->rune === 'p' || $msg->rune === 'P'))
            && $menu->state() === 1) {
            $next = clone $this;
            $next->syncPlayModal = $menu->togglePublic();

            return [$next, null];
        }

        // Up/Down navigation in list state
        if ($menu->state() === 0) {
            if ($msg->type === KeyType::Up) {
                $next = clone $this;
                $next->syncPlayModal = $menu->up();

                return [$next, null];
            }
            if ($msg->type === KeyType::Down) {
                $next = clone $this;
                $next->syncPlayModal = $menu->down();

                return [$next, null];
            }
        }

        // Enter: select current item
        if ($msg->type === KeyType::Enter) {
            [$modalAfterSelect, $action] = $menu->select();

            $next = clone $this;
            $next->syncPlayModal = $modalAfterSelect;

            if ($action === null) {
                return [$next, null];
            }

            if ($action === 'create') {
                // Create a new room
                $roomName = $modalAfterSelect->roomName() ?? '';
                $isPublic = $modalAfterSelect->isPublic();
                $next->syncPlayModal = $modalAfterSelect->joining();

                // Return a Cmd::promise that resolves to SyncPlayJoinedMsg or SyncPlayFailedMsg
                $createCmd = Cmd::promise(
                    fn (): PromiseInterface => $this->api->createSyncPlayRoom($roomName, $isPublic)->then(
                        static function ($session) use ($roomName, $isPublic): Msg {
                            return new SyncPlayJoinedMsg(new SyncPlayRoom(
                                $session->roomId,
                                $roomName,
                                $isPublic,
                                1,
                            ));
                        },
                        static fn (\Throwable $e): Msg => new SyncPlayFailedMsg('Failed to create room: ' . $e->getMessage()),
                    ),
                );

                return [$next, $createCmd];
            }

            // Joining a room
            $joinCmd = Cmd::promise(
                fn (): PromiseInterface => $this->api->joinSyncPlayRoom($action)->then(
                    static function ($session): Msg {
                        return new SyncPlayJoinedMsg(new SyncPlayRoom(
                            $session->roomId,
                            'Room',
                            true,
                            1,
                        ));
                    },
                    static fn (\Throwable $e): Msg => new SyncPlayFailedMsg('Failed to join room: ' . $e->getMessage()),
                ),
            );

            return [$next, $joinCmd];
        }

        // Backspace in creation state
        if ($msg->type === KeyType::Backspace && $menu->state() === 1) {
            $next = clone $this;
            $next->syncPlayModal = $menu->backspace();

            return [$next, null];
        }

        // Regular characters in creation state: append to room name
        if ($msg->type === KeyType::Char && $menu->state() === 1) {
            $next = clone $this;
            $next->syncPlayModal = $menu->appendChar($msg->rune);

            return [$next, null];
        }

        // Any other key in error state: dismiss
        if ($menu->state() === 3) {
            $next = clone $this;
            $next->syncPlayModal = null;

            return [$next, null];
        }

        return [$this, null];
    }

    // ---- SyncPlay message handlers --------------------------------------

    /**
     * Handle rooms list loaded - update the modal with rooms.
     *
     * @param list<SyncPlayRoom> $rooms
     * @return array{self, ?\Closure}
     */
    private function onSyncPlayRoomsLoaded(array $rooms): array
    {
        if ($this->syncPlayModal === null) {
            return [$this, null];
        }

        $next = clone $this;
        $next->syncPlayModal = SyncPlayModal::open($rooms, $this->cols, $this->rows);

        return [$next, null];
    }

    /**
     * Handle successful room join - close modal and show overlay.
     *
     * @return array{self, ?\Closure}
     */
    private function onSyncPlayJoined(SyncPlayRoom $room): array
    {
        $next = clone $this;
        $next->syncPlayModal = null;
        $next->syncPlayRoomName = $room->name;
        $next->syncPlayMemberCount = $room->memberCount;
        $next->syncPlayStatus = 'Connecting...';

        // Start SyncPlay service if available
        if ($this->syncPlayService !== null) {
            $this->syncPlayService->joinRoom($room->id);
        }

        return [$next, null];
    }

    /** @return array{self, ?\Closure} */
    private function onSyncPlayLeft(): array
    {
        $next = clone $this;
        $next->syncPlayModal = null;
        $next->syncPlayRoomName = null;
        $next->syncPlayMemberCount = 0;
        $next->syncPlayStatus = 'Not in room';

        // Stop SyncPlay service if available
        if ($this->syncPlayService !== null) {
            $this->syncPlayService->leaveRoom();
        }

        return [$next, null];
    }

    /**
     * Handle SyncPlay failure - show error in modal or close and toast.
     *
     * @return array{self, ?\Closure}
     */
    private function onSyncPlayFailed(string $reason): array
    {
        if ($this->syncPlayModal !== null) {
            $next = clone $this;
            $next->syncPlayModal = $this->syncPlayModal->withError($reason);

            return [$next, null];
        }

        // If modal not open, just log - could show a toast here
        return [$this, null];
    }

    /**
     * Handle member joined - update member count.
     *
     * @return array{self, ?\Closure}
     */
    private function onSyncPlayMemberJoined(SyncPlayUser $member): array
    {
        $next = clone $this;
        $next->syncPlayMemberCount = $this->syncPlayService?->getMemberCount() ?? ($this->syncPlayMemberCount + 1);
        $next->syncPlayStatus = $this->syncPlayService?->getSyncStatus() ?? 'Synced';

        return [$next, null];
    }

    /**
     * Handle member left - update member count.
     *
     * @return array{self, ?\Closure}
     */
    private function onSyncPlayMemberLeft(string $memberId): array
    {
        $next = clone $this;
        $next->syncPlayMemberCount = max(1, ($this->syncPlayService?->getMemberCount() ?? $this->syncPlayMemberCount) - 1);
        $next->syncPlayStatus = $this->syncPlayService?->getSyncStatus() ?? 'Synced';

        return [$next, null];
    }

    /**
     * Handle playback command from SyncPlay host - pause/play/seek the player.
     *
     * @return array{self, ?\Closure}
     */
    private function onSyncPlayPlaybackCommand(SyncPlayPlaybackCommand $command): array
    {
        if ($this->inner === null) {
            return [$this, null];
        }

        $next = clone $this;
        $positionSeconds = $command->position / 1000.0;

        switch ($command->type) {
            case 'pause':
                $next->inner = $this->inner->pause();
                $next->syncPlayStatus = 'Paused';
                break;

            case 'play':
                $next->inner = $this->inner->play();
                $next->syncPlayStatus = 'Synced';
                break;

            case 'seek':
                $next->inner = $this->inner->seekToSeconds($positionSeconds);
                $next->syncPlayStatus = 'Synced';
                break;
        }

        return [$next, null];
    }

    /** @return array{self, ?\Closure} */
    private function onResize(int $cols, int $rows): array
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;
        if ($this->audioTrackMenu !== null) {
            $next->audioTrackMenu = $this->audioTrackMenu->resizedTo($cols, $rows);
        }
        if ($this->subtitleTrackMenu !== null) {
            $next->subtitleTrackMenu = $this->subtitleTrackMenu->resizedTo($cols, $rows);
        }
        if ($this->qualityMenu !== null) {
            $next->qualityMenu = $this->qualityMenu->resizedTo($cols, $rows);
        }
        if ($this->syncPlayModal !== null) {
            $next->syncPlayModal = $this->syncPlayModal->resizedTo($cols, $rows);
        }

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
        $quality = $this->variants !== [] ? '  v quality' : '';
        $hint = 'Space ⏯  ←→ ±10s  [ ] speed  m mode' . $cc . $quality . $nav . '  q back';

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
        return $this->markers->chapters ?? [];
    }

    /** Resolve the item's signed stream URL against the server base (handles a relative path). */
    private function streamUrl(): string
    {
        return $this->resolveUrl($this->item->streamUrl ?? '');
    }

    /** Resolve a (possibly relative) URL against the server base; absolute/empty pass through. */
    private function resolveUrl(string $url): string
    {
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

    public function isTranscoding(): bool
    {
        return $this->transcoding && $this->inner === null;
    }

    public function transcodeJob(): ?string
    {
        return $this->transcodeJob;
    }

    /** @return list<Rendition> the ABR ladder rungs offered for this item (empty = none). */
    public function variants(): array
    {
        return $this->variants;
    }

    /** The pinned rendition id, or null for "Auto" (server ABR). */
    public function selectedQuality(): ?string
    {
        return $this->selectedQuality;
    }

    public function qualityMenu(): ?QualityMenu
    {
        return $this->qualityMenu;
    }

    public function isQualityMenuOpen(): bool
    {
        return $this->qualityMenu !== null;
    }

    /** @return list<StreamAudioTrack> the available audio tracks. */
    public function audioTracks(): array
    {
        return $this->audioTracks;
    }

    /** @return list<StreamSubtitleTrack> the available subtitle tracks. */
    public function subtitleTracks(): array
    {
        return $this->subtitleTracks;
    }

    /** The selected audio track id, or null for the first track. */
    public function selectedAudioTrack(): ?string
    {
        return $this->selectedAudioTrack;
    }

    /** The selected subtitle track id, or null for "off". */
    public function selectedSubtitleTrack(): ?string
    {
        return $this->selectedSubtitleTrack;
    }

    public function audioTrackMenu(): ?AudioTrackList
    {
        return $this->audioTrackMenu;
    }

    public function isAudioTrackMenuOpen(): bool
    {
        return $this->audioTrackMenu !== null;
    }

    public function subtitleTrackMenu(): ?SubtitleTrackList
    {
        return $this->subtitleTrackMenu;
    }

    public function isSubtitleTrackMenuOpen(): bool
    {
        return $this->subtitleTrackMenu !== null;
    }

    public function syncPlayModal(): ?SyncPlayModal
    {
        return $this->syncPlayModal;
    }

    public function isSyncPlayModalOpen(): bool
    {
        return $this->syncPlayModal !== null;
    }

    public function syncPlayRoomName(): ?string
    {
        return $this->syncPlayRoomName;
    }

    public function syncPlayMemberCount(): int
    {
        return $this->syncPlayMemberCount;
    }

    public function syncPlayStatus(): string
    {
        return $this->syncPlayStatus;
    }
}

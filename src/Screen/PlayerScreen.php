<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\PlaybackMarkers;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\PlaybackMarkersLoadedMsg;
use Phlix\Console\Msg\PlayerPrepareFailedMsg;
use Phlix\Console\Msg\PlayerReadyMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
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

    /** Rows reserved below the video: the scrubber + status line + 1 slack for the inner player's own status line. */
    private const CHROME_ROWS = 3;
    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';

    private ?Player $inner = null;
    private ?string $error = null;
    private bool $chromeHidden = false;
    private bool $tornDown = false;
    private ?PlaybackMarkers $markers = null;

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
        // Build the player and fetch its scrubber markers concurrently.
        return Cmd::batch(
            Cmd::promise(fn (): PromiseInterface => $this->buildPlayer()),
            $this->fetchMarkers(),
        );
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

        return $frame . "\n" . $this->scrubberLine($this->inner) . "\n" . $this->statusLine($this->inner);
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

    private function onReady(Player $player): array
    {
        // Auto-play: Space toggles the inner player out of its paused initial
        // state — it starts audio and arms the tick chain. The returned Cmd is
        // that first tick, which flows back up to drive the frame pump.
        [$started, $cmd] = $player->update(new KeyMsg(KeyType::Space));

        $next = clone $this;
        $next->inner = $started instanceof Player ? $started : $player;

        return [$next, $cmd];
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
        // Esc / q → tear down the subprocesses and pop back to the detail screen.
        // (Intercepted BEFORE forwarding so the inner player's own quit — which
        // would Cmd::quit the whole app — never fires.)
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))) {
            $this->teardown();

            return [$this, Cmd::send(new NavigateBackMsg())];
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

        [$nextInner, $cmd] = $inner->update($msg);

        $next = clone $this;
        $next->inner = $nextInner instanceof Player ? $nextInner : $inner;

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

    /** The progress bar with chapter ticks. */
    private function scrubberLine(Player $inner): string
    {
        return Scrubber::of($inner->position(), $inner->duration(), $this->cols, $this->chapters())->render();
    }

    /** play/pause glyph + title + a contextual skip prompt + the key hints. */
    private function statusLine(Player $inner): string
    {
        $glyph = $inner->paused ? '⏸' : '▶';
        $skip = $this->markers?->skipLabel($inner->position());
        $skipPrompt = $skip !== null ? "   s {$skip}" : '';
        $hint = 'Space ⏯  ←→ ±10s  [ ] speed  m mode  f full  q back';

        $title = Width::truncate($this->item->name, max(8, $this->cols - 56));
        $line = sprintf('%s %s%s   ·   %s', $glyph, $title, $skipPrompt, $hint);

        return Style::new()->bold()->render(Width::truncate($line, max(1, $this->cols)));
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
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Cast\CastClient;
use Phlix\Console\Api\Dto\Cast\CastDevice;
use Phlix\Console\Api\Dto\Cast\CastStatus;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Msg\CastActionDoneMsg;
use Phlix\Console\Msg\CastActionFailedMsg;
use Phlix\Console\Msg\CastDevicesFailedMsg;
use Phlix\Console\Msg\CastDevicesLoadedMsg;
use Phlix\Console\Msg\CastFailedMsg;
use Phlix\Console\Msg\CastStartedMsg;
use Phlix\Console\Msg\CastStatusLoadedMsg;
use Phlix\Console\Msg\CastStatusTickMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The "Cast to…" surface: discover cast targets across all four backends, pick
 * one, send the current video item's signed stream to it, then drive a small
 * transport overlay (pause / resume / stop) with a live status poll.
 *
 * Three modes, held in {@see $mode}:
 *  • DISCOVERING — `init()` fans out {@see CastClient::discover()} and shows
 *    "Searching for devices…".
 *  • PICKER — a windowed {@see Table} of devices (Device · Type · Detail); ↑/↓
 *    select, `r` rescan, Enter sends to the selection (→ Transport), Esc/q back.
 *    An empty list shows a "no devices / r to rescan" placeholder.
 *  • TRANSPORT — a header showing the device + title + last-known state, with
 *    Space pause/resume (capability-gated: DLNA has no resume, so Space only
 *    pauses), `x` stop (only when the backend `canStop()` — Roku has none),
 *    `r` refresh-now, Esc back to the picker (the remote session keeps playing).
 *
 * Casting is FIRE-TO-DEVICE, not a persistent now-playing session: leaving
 * Transport via Esc returns to the picker but LEAVES the device playing; only `x`
 * explicitly stops it. The screen is deliberately NOT {@see Teardownable} —
 * popping it does NOT force-stop the device; it only drops the status-poll tick
 * via the epoch guard.
 *
 * The status poll is an epoch-guarded {@see Cmd::tick}: each {@see CastStatusTickMsg}
 * whose `epoch` matches the current {@see $pollEpoch} fires {@see CastClient::status()}
 * and re-arms; the epoch is bumped on entering Transport (a fresh chain) and on
 * leaving it (so any in-flight tick is dropped). No seek key is offered this PR
 * (the status endpoints expose no uniform position, and Roku/AirPlay can't seek —
 * {@see CastClient::seek()} stays for a future PR once a position source exists).
 *
 * Stable collaborators are readonly; the mode, device list, selection, bound
 * device, paused flag, last state, and poll epoch are private mutable view state
 * set via clone-mutate (the established screen idiom).
 */
final class CastScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const MODE_DISCOVERING = 'discovering';
    private const MODE_PICKER = 'picker';
    private const MODE_TRANSPORT = 'transport';

    /** Seconds between status polls while in Transport mode. */
    private const STATUS_INTERVAL = 2.5;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';

    private const PICKER_HINT = '↑↓  select      ⏎  cast      r  rescan      Esc  back';

    private string $mode = self::MODE_DISCOVERING;

    /** @var list<CastDevice> */
    private array $devices = [];
    private bool $loaded = false;
    private ?string $error = null;
    private int $selected = 0;

    /** The device Transport is bound to, or null while discovering / picking. */
    private ?CastDevice $active = null;
    private bool $paused = false;
    private ?string $state = null;

    /** The status-poll generation; an armed tick carries this. */
    private int $pollEpoch = 0;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly CastClient $cast,
        private readonly MediaItem $item,
        private readonly string $baseUrl,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->discoverCmd();
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof CastDevicesLoadedMsg) {
            return [$this->withDevices($msg->devices), null];
        }
        if ($msg instanceof CastDevicesFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof CastStartedMsg) {
            return $this->onCastStarted($msg->device);
        }
        if ($msg instanceof CastFailedMsg) {
            return [$this->toPicker(), Cmd::send(ShowToastMsg::error($msg->message))];
        }
        if ($msg instanceof CastStatusTickMsg) {
            return $this->onStatusTick($msg->epoch);
        }
        if ($msg instanceof CastStatusLoadedMsg) {
            return [$this->withStatus($msg->epoch, $msg->status), null];
        }
        if ($msg instanceof CastActionDoneMsg) {
            return [$this->withState($msg->state), null];
        }
        if ($msg instanceof CastActionFailedMsg) {
            return [$this, Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Cast', $this->body(), $this->hint(), $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // Transport keys need the bound device; the mode and the device are set /
        // cleared together, so $active is non-null exactly in Transport mode.
        $device = $this->active;
        if ($this->mode === self::MODE_TRANSPORT && $device !== null) {
            return $this->handleTransportKey($msg, $device);
        }

        return $this->handlePickerKey($msg);
    }

    /** @return array{self, ?\Closure} */
    private function handlePickerKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return $this->rescan();
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->castSelected();
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleTransportKey(KeyMsg $msg, CastDevice $device): array
    {
        if ($msg->type === KeyType::Escape) {
            // Leave Transport but LEAVE the session playing; drop the poll by
            // bumping the epoch so any in-flight tick is ignored.
            return [$this->toPicker(), null];
        }
        if ($msg->type === KeyType::Space) {
            return $this->togglePause($device);
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this, $this->statusFetchCmd($device, $this->pollEpoch)];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'x' && $device->backend->canStop()) {
            return $this->stop($device);
        }

        return [$this, null];
    }

    // ---- discovery -----------------------------------------------------

    private function discoverCmd(): \Closure
    {
        // discover() is per-backend fault-tolerant (each leg swallows its own
        // failure into an empty slice), so the fan-out resolves to a (possibly
        // empty) list and never rejects — an empty list lands in the picker with
        // the "no devices / r to rescan" placeholder. The screen still handles a
        // {@see CastDevicesFailedMsg} (error state + `r` retry) defensively, but the
        // real discover() never produces one.
        return Cmd::promise(fn (): PromiseInterface => $this->cast->discover()->then(
            /** @param list<CastDevice> $devices */
            static fn (array $devices): Msg => new CastDevicesLoadedMsg($devices),
        ));
    }

    /** @return array{self, ?\Closure} */
    private function rescan(): array
    {
        $next = clone $this;
        $next->mode = self::MODE_DISCOVERING;
        $next->devices = [];
        $next->loaded = false;
        $next->error = null;
        $next->selected = 0;

        return [$next, $next->discoverCmd()];
    }

    // ---- cast send -----------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function castSelected(): array
    {
        $device = $this->selectedDevice();
        if ($device === null) {
            return [$this, null];
        }

        return [$this, $this->castCmd($device)];
    }

    private function castCmd(CastDevice $device): \Closure
    {
        $promise = $this->cast->castTo(
            $device,
            $this->item->id,
            $this->resolveUrl($this->item->streamUrl ?? ''),
            $this->item->name,
            $this->item->posterUrl !== null ? $this->resolveUrl($this->item->posterUrl) : null,
            $this->item->runtime ?? $this->item->duration,
        );

        return Cmd::promise(static fn () => $promise->then(
            static fn (string $sessionId): Msg => new CastStartedMsg($device),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new CastFailedMsg($e->getMessage()),
        ));
    }

    /** @return array{self, ?\Closure} */
    private function onCastStarted(CastDevice $device): array
    {
        $next = clone $this;
        $next->mode = self::MODE_TRANSPORT;
        $next->active = $device;
        $next->paused = false;
        $next->state = null;
        // Arm a fresh poll generation for this device.
        $next->pollEpoch = $this->pollEpoch + 1;

        return [$next, $next->statusTickCmd($next->pollEpoch)];
    }

    // ---- transport -----------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function togglePause(CastDevice $device): array
    {
        // DLNA has no resume endpoint: once paused, Space only ever pauses (a
        // no-op resume would be dishonest). Otherwise Space toggles.
        if ($this->paused && !$device->backend->canResume()) {
            return [$this, null];
        }

        $resume = $this->paused;
        $next = clone $this;
        $next->paused = !$this->paused;

        $promise = $resume ? $this->cast->resume($device) : $this->cast->pause($device);

        return [$next, $this->actionCmd($promise)];
    }

    /** @return array{self, ?\Closure} */
    private function stop(CastDevice $device): array
    {
        // Stop the remote session, then return to the picker. The poll is dropped
        // (toPicker bumps the epoch).
        $promise = $this->cast->stop($device);

        return [$this->toPicker(), $this->actionCmd($promise)];
    }

    /**
     * Map a transport-action promise to a done/failed Msg.
     *
     * @param PromiseInterface<string> $promise
     */
    private function actionCmd(PromiseInterface $promise): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (string $state): Msg => new CastActionDoneMsg($state),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new CastActionFailedMsg($e->getMessage()),
        ));
    }

    /** @return array{self, ?\Closure} */
    private function onStatusTick(int $epoch): array
    {
        // A stale tick (the screen left Transport / re-armed under a new epoch) is
        // dropped, killing that chain.
        if ($this->mode !== self::MODE_TRANSPORT || $epoch !== $this->pollEpoch || $this->active === null) {
            return [$this, null];
        }

        // Fetch the status AND re-arm the next tick under the same epoch.
        return [$this, Cmd::batch($this->statusFetchCmd($this->active, $epoch), $this->statusTickCmd($epoch))];
    }

    private function statusTickCmd(int $epoch): \Closure
    {
        return Cmd::tick(self::STATUS_INTERVAL, static fn (): Msg => new CastStatusTickMsg($epoch));
    }

    private function statusFetchCmd(CastDevice $device, int $epoch): \Closure
    {
        return Cmd::promise(fn (): PromiseInterface => $this->cast->status($device)->then(
            static fn (CastStatus $status): Msg => new CastStatusLoadedMsg($epoch, $status),
            // A failed status poll is best-effort — keep the last-known line, never crash.
            static fn (\Throwable $e): ?Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : null,
        ));
    }

    // ---- clone-mutate copies -------------------------------------------

    /** @param list<CastDevice> $devices */
    private function withDevices(array $devices): self
    {
        $next = clone $this;
        $next->mode = self::MODE_PICKER;
        $next->devices = $devices;
        $next->loaded = true;
        $next->error = null;
        $next->selected = $devices === [] ? 0 : min($this->selected, count($devices) - 1);

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->mode = self::MODE_PICKER;
        $next->error = $error;
        $next->loaded = false;

        return $next;
    }

    /** Return to the picker, dropping the status poll (the epoch bump strands it). */
    private function toPicker(): self
    {
        $next = clone $this;
        $next->mode = self::MODE_PICKER;
        $next->active = null;
        $next->paused = false;
        $next->state = null;
        $next->pollEpoch = $this->pollEpoch + 1;

        return $next;
    }

    private function withStatus(int $epoch, CastStatus $status): self
    {
        // Drop a status that resolved for a superseded poll generation.
        if ($epoch !== $this->pollEpoch) {
            return $this;
        }
        $next = clone $this;
        $next->state = $status->state ?? ($status->active ? 'playing' : 'idle');
        $next->paused = $status->state !== null && strtolower($status->state) === 'paused';

        return $next;
    }

    private function withState(string $state): self
    {
        if ($state === '') {
            return $this;
        }
        $next = clone $this;
        $next->state = $state;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->devices);
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

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        // In Transport mode the bound device is always set (mode + device move
        // together), so render the transport view from it.
        if ($this->mode === self::MODE_TRANSPORT && $this->active !== null) {
            return $this->transportBody($this->active);
        }
        if ($this->mode === self::MODE_DISCOVERING) {
            return "\n  Searching for devices…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to rescan.";
        }
        if ($this->devices === []) {
            return "\n  No cast devices found on the network. (r to rescan)";
        }

        return "\n" . $this->castingLine() . "\n" . $this->deviceTable();
    }

    private function castingLine(): string
    {
        return '  Cast “' . $this->item->name . '” to:';
    }

    private function deviceTable(): string
    {
        $rows = [];
        foreach ($this->devices as $device) {
            $rows[] = [
                $device->name === '' ? '(unnamed)' : $device->name,
                $device->backend->label(),
                $device->model ?? $device->detail ?? '—',
            ];
        }

        return Table::render([
            ['title' => 'Device', 'width' => 0],
            ['title' => 'Type', 'width' => 14],
            ['title' => 'Detail', 'width' => 28],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());
    }

    private function transportBody(CastDevice $device): string
    {
        $glyph = $this->paused ? '⏸' : '▶';
        $state = $this->state !== null && $this->state !== '' ? $this->state : ($this->paused ? 'paused' : 'playing');
        $header = sprintf('  Casting to %s', $device->label());
        $now = sprintf('  %s %s · %s', $glyph, $this->item->name, $state);

        return "\n" . $header . "\n\n" . $now;
    }

    private function hint(): string
    {
        if ($this->mode === self::MODE_TRANSPORT) {
            return $this->transportHint();
        }

        return self::PICKER_HINT;
    }

    private function transportHint(): string
    {
        $device = $this->active;
        $space = ($device !== null && !$device->backend->canResume())
            ? 'Space  pause'
            : 'Space  pause/resume';
        $stop = ($device !== null && $device->backend->canStop()) ? '      x  stop' : '';

        return $space . $stop . '      r  refresh      Esc  back';
    }

    private function viewportRows(): int
    {
        // The frame body holds the "Cast … to:" line, then the table (header + rule
        // = 2 extra rows). Window the data rows to the body height less that chrome.
        return max(1, Chrome::bodyHeight($this->rows) - 3);
    }

    /** Resolve a (possibly relative) URL against the server base; absolute/empty pass through. */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private function selectedDevice(): ?CastDevice
    {
        return $this->devices[$this->selected] ?? null;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Cast';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function mode(): string
    {
        return $this->mode;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /** @return list<CastDevice> */
    public function deviceList(): array
    {
        return $this->devices;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function activeDevice(): ?CastDevice
    {
        return $this->active;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function state(): ?string
    {
        return $this->state;
    }

    /** The current status-poll generation (an armed status tick carries this). */
    public function pollEpoch(): int
    {
        return $this->pollEpoch;
    }
}

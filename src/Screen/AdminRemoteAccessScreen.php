<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\RemoteAccessStatus;
use Phlix\Console\Msg\AdminRemoteActionDoneMsg;
use Phlix\Console\Msg\AdminRemoteActionFailedMsg;
use Phlix\Console\Msg\AdminRemoteFailedMsg;
use Phlix\Console\Msg\AdminRemoteStatusLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The admin remote-access surface: four stacked, individually-selectable status
 * panels — Hub pairing, managed Subdomain, Relay tunnel, and Port forwarding —
 * each fetched together (via {@see AdminClient::remoteStatus()}) on init.
 *
 * ↑/↓ moves the selection between the four panels (clamped); the selected panel
 * is accented and its available actions appear in the hint. Action keys are
 * interpreted PER selected panel:
 *  - Hub: `u` unenroll (only when paired — inline `y/n` confirm; removes the
 *    pairing). When unpaired the panel notes "Pair from the web admin" (the
 *    interactive pairing wizard is deferred).
 *  - Subdomain: `c` claim (when not claimed), `x` release (when claimed — `y/n`).
 *  - Relay: `e` enable, `d` disable, `p` ping (toasts the latency).
 *  - Port Forward: `e` enable, `d` disable.
 *
 * After any successful action the confirmation is toasted and ALL four statuses
 * are refetched (no live poll — statuses are point-in-time). A failure toasts the
 * friendly server `message` (the failure bodies use `message`, not `error`; the
 * {@see AdminClient} re-surfaces it) and leaves the statuses unchanged. An auth
 * failure surfaces a session expiry. `r` refreshes.
 *
 * Stable collaborators are readonly; the loaded status, selected panel, busy
 * flag, pending confirm, and error are private mutable view state set via
 * clone-mutate (the established screen idiom).
 */
final class AdminRemoteAccessScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the remote-access status.';

    private const PANEL_HUB = 0;
    private const PANEL_SUBDOMAIN = 1;
    private const PANEL_RELAY = 2;
    private const PANEL_PORTFORWARD = 3;
    private const PANEL_COUNT = 4;

    private ?RemoteAccessStatus $status = null;
    private bool $loaded = false;
    private ?string $error = null;

    /** Which of the four panels is selected (PANEL_* constant). */
    private int $panel = self::PANEL_HUB;

    /** A fetch / action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

    /**
     * The armed confirm: 'unenroll' (hub) or 'release' (subdomain) when a y/n
     * confirm is pending, else null.
     */
    private ?string $pendingConfirm = null;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchCmd();
    }

    // ---- fetch ---------------------------------------------------------

    private function fetchCmd(): \Closure
    {
        $admin = $this->admin;

        return Cmd::promise(static fn () => $admin->remoteStatus()->then(
            static fn (RemoteAccessStatus $status): Msg => new AdminRemoteStatusLoadedMsg($status),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminRemoteFailedMsg(self::LOAD_FAILED),
        ));
    }

    /**
     * Build the command for a fired action: the action's promise mapped to a
     * done/failed Msg. On rejection the friendly server `message` (re-surfaced by
     * the client) is toasted; an auth failure becomes a session expiry.
     *
     * @param PromiseInterface<string> $promise
     */
    private function actionCmd(PromiseInterface $promise): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (string $message): Msg => new AdminRemoteActionDoneMsg($message),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminRemoteActionFailedMsg($e->getMessage()),
        ));
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
        if ($msg instanceof AdminRemoteStatusLoadedMsg) {
            return [$this->withStatus($msg->status), null];
        }
        if ($msg instanceof AdminRemoteFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminRemoteActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminRemoteActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin · Remote Access', $this->body(), $this->hint(), $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // A pending y/n confirm captures every key until it is answered.
        if ($this->pendingConfirm !== null) {
            return $this->handleConfirmKey($msg, $this->pendingConfirm);
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->movePanel(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->movePanel(1), null];
        }
        if ($msg->type === KeyType::Char) {
            return $this->handleCharKey($msg->rune);
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleCharKey(string $rune): array
    {
        if ($rune === 'r') {
            return $this->refresh();
        }
        if ($this->busy) {
            return [$this, null];
        }

        $status = $this->status;
        if ($status === null) {
            return [$this, null];
        }

        return match ($this->panel) {
            self::PANEL_HUB => $this->hubAction($rune, $status),
            self::PANEL_SUBDOMAIN => $this->subdomainAction($rune, $status),
            self::PANEL_RELAY => $this->relayAction($rune),
            self::PANEL_PORTFORWARD => $this->portForwardAction($rune),
            default => [$this, null],
        };
    }

    /** @return array{self, ?\Closure} */
    private function hubAction(string $rune, RemoteAccessStatus $status): array
    {
        // `u` unenroll is offered only when paired, and arms a y/n confirm. The
        // pairing wizard is deferred — the panel notes "pair from the web admin".
        if ($rune === 'u' && $status->hub->paired) {
            return [$this->armConfirm('unenroll'), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function subdomainAction(string $rune, RemoteAccessStatus $status): array
    {
        if ($rune === 'c' && !$status->subdomain->claimed) {
            return [$this->working(), $this->actionCmd($this->admin->subdomainClaim())];
        }
        if ($rune === 'x' && $status->subdomain->claimed) {
            return [$this->armConfirm('release'), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function relayAction(string $rune): array
    {
        if ($rune === 'e') {
            return [$this->working(), $this->actionCmd($this->admin->relayEnable())];
        }
        if ($rune === 'd') {
            return [$this->working(), $this->actionCmd($this->admin->relayDisable())];
        }
        if ($rune === 'p') {
            return [$this->working(), $this->actionCmd($this->admin->relayPing())];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function portForwardAction(string $rune): array
    {
        if ($rune === 'e') {
            return [$this->working(), $this->actionCmd($this->admin->portForwardEnable())];
        }
        if ($rune === 'd') {
            return [$this->working(), $this->actionCmd($this->admin->portForwardDisable())];
        }

        return [$this, null];
    }

    /**
     * Resolve an armed y/n confirm: `y` performs the action, any other key cancels.
     *
     * @return array{self, ?\Closure}
     */
    private function handleConfirmKey(KeyMsg $msg, string $confirm): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            $promise = $confirm === 'unenroll'
                ? $this->admin->hubUnenroll()
                : $this->admin->subdomainRelease();

            return [$this->disarmAndWork(), $this->actionCmd($promise)];
        }

        // n / Esc / anything else cancels — no request issued.
        return [$this->disarm(), null];
    }

    // ---- action results ------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function onActionDone(AdminRemoteActionDoneMsg $msg): array
    {
        // Actions are immediate; just refetch all four statuses so the change shows.
        return [$this->working(), Cmd::batch(Cmd::send(ShowToastMsg::success($msg->message)), $this->fetchCmd())];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;
        $next->busy = true;
        $next->pendingConfirm = null;

        return [$next, $next->fetchCmd()];
    }

    // ---- clone-mutate copies -------------------------------------------

    private function withStatus(RemoteAccessStatus $status): self
    {
        $next = clone $this;
        $next->status = $status;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = false;
        $next->busy = false;

        return $next;
    }

    /** Move the panel selection by $delta, clamped to the four panels. */
    private function movePanel(int $delta): self
    {
        $panel = max(0, min(self::PANEL_COUNT - 1, $this->panel + $delta));
        if ($panel === $this->panel) {
            return $this;
        }
        $next = clone $this;
        $next->panel = $panel;

        return $next;
    }

    /** Arm a y/n confirm for the given action. */
    private function armConfirm(string $action): self
    {
        $next = clone $this;
        $next->pendingConfirm = $action;

        return $next;
    }

    /** Clear an armed confirm without acting. */
    private function disarm(): self
    {
        $next = clone $this;
        $next->pendingConfirm = null;

        return $next;
    }

    /** Clear the confirm and enter the busy state (a confirmed action fired). */
    private function disarmAndWork(): self
    {
        $next = clone $this;
        $next->pendingConfirm = null;
        $next->busy = true;

        return $next;
    }

    /** Enter the busy (in-flight) state. */
    private function working(): self
    {
        $next = clone $this;
        $next->busy = true;

        return $next;
    }

    /** Leave the busy state (after a failed action) without touching the status. */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;

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
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }

        $status = $this->status;
        if ($status === null) {
            return "\n  Loading remote-access status…";
        }

        $lines = [''];
        foreach ($this->panelLines($status) as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = '  ' . $this->statusLine();

        return implode("\n", $lines);
    }

    /**
     * Render the four panels in order, each headed by its (optionally accented)
     * title and followed by its detail lines.
     *
     * @return list<string>
     */
    private function panelLines(RemoteAccessStatus $status): array
    {
        $out = [];
        foreach ($this->hubPanel($status) as $line) {
            $out[] = $line;
        }
        $out[] = '';
        foreach ($this->subdomainPanel($status) as $line) {
            $out[] = $line;
        }
        $out[] = '';
        foreach ($this->relayPanel($status) as $line) {
            $out[] = $line;
        }
        $out[] = '';
        foreach ($this->portForwardPanel($status) as $line) {
            $out[] = $line;
        }

        return $out;
    }

    /** @return list<string> */
    private function hubPanel(RemoteAccessStatus $status): array
    {
        $hub = $status->hub;
        $lines = [
            $this->panelTitle(self::PANEL_HUB, 'Hub Pairing', $hub->stateLabel()),
        ];
        if ($hub->paired) {
            $lines[] = '    Server ID:   ' . ($hub->serverId ?? '—');
            $lines[] = '    Hub URL:     ' . ($hub->hubUrl ?? '—');
            $lines[] = '    Enrolled at: ' . ($hub->enrolledAt ?? '—');
        } else {
            $lines[] = '    Pair from the web admin (pairing wizard not available here).';
        }

        return $lines;
    }

    /** @return list<string> */
    private function subdomainPanel(RemoteAccessStatus $status): array
    {
        $sub = $status->subdomain;
        $lines = [
            $this->panelTitle(self::PANEL_SUBDOMAIN, 'Subdomain', $sub->stateLabel()),
        ];
        if ($sub->claimed) {
            $lines[] = '    Subdomain:   ' . ($sub->subdomain ?? '—');
            $lines[] = '    FQDN:        ' . ($sub->fqdn ?? '—');
        } else {
            $lines[] = '    No subdomain claimed.';
        }

        return $lines;
    }

    /** @return list<string> */
    private function relayPanel(RemoteAccessStatus $status): array
    {
        $relay = $status->relay;

        return [
            $this->panelTitle(self::PANEL_RELAY, 'Relay Tunnel', $relay->stateLabel()),
            '    Connected:   ' . ($relay->connected ? 'yes' : 'no'),
            '    Active:      ' . ($relay->active ? 'yes' : 'no'),
            '    Since:       ' . ($relay->establishedAt ?? '—'),
        ];
    }

    /** @return list<string> */
    private function portForwardPanel(RemoteAccessStatus $status): array
    {
        $pf = $status->portForward;
        $lines = [
            $this->panelTitle(self::PANEL_PORTFORWARD, 'Port Forward', $pf->stateLabel()),
        ];
        if ($pf->enabled) {
            $lines[] = '    Method:      ' . ($pf->method ?? '—');
            $lines[] = '    External IP: ' . ($pf->externalIp ?? '—');
            $lines[] = '    Port:        ' . ($pf->externalPort !== null ? (string) $pf->externalPort : '—');
            $lines[] = '    Hostname:    ' . ($pf->hostname ?? '—');
        } else {
            $lines[] = '    Port forwarding disabled.';
        }

        return $lines;
    }

    /** The panel header, marked with a ▸ caret when it is the selected panel. */
    private function panelTitle(int $panel, string $label, string $state): string
    {
        $caret = $panel === $this->panel ? '▸ ' : '  ';

        return $caret . $label . ' — ' . $state;
    }

    /** A line under the panels: the busy note, the confirm prompt, or a hint. */
    private function statusLine(): string
    {
        if ($this->pendingConfirm !== null) {
            return $this->pendingConfirm === 'unenroll'
                ? 'Unenroll from the hub? (y/n)'
                : 'Release the subdomain? (y/n)';
        }
        if ($this->busy) {
            return 'Working…';
        }

        return '↑↓ select panel · acting on: ' . $this->panelName();
    }

    private function panelName(): string
    {
        return match ($this->panel) {
            self::PANEL_HUB => 'Hub Pairing',
            self::PANEL_SUBDOMAIN => 'Subdomain',
            self::PANEL_RELAY => 'Relay Tunnel',
            self::PANEL_PORTFORWARD => 'Port Forward',
            default => '',
        };
    }

    /** The frame hint reflects the selected panel's available actions. */
    private function hint(): string
    {
        $status = $this->status;
        if ($status === null || $this->error !== null) {
            return 'r refresh   Esc back';
        }
        if ($this->pendingConfirm !== null) {
            return 'y confirm   n cancel';
        }

        return $this->panelActionHint($status) . '   ↑↓ panel   r refresh   Esc back';
    }

    private function panelActionHint(RemoteAccessStatus $status): string
    {
        return match ($this->panel) {
            self::PANEL_HUB => $status->hub->paired ? 'u unenroll' : '(pair from web admin)',
            self::PANEL_SUBDOMAIN => $status->subdomain->claimed ? 'x release' : 'c claim',
            self::PANEL_RELAY => 'e enable   d disable   p ping',
            self::PANEL_PORTFORWARD => 'e enable   d disable',
            default => '',
        };
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Remote Access';
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

    public function status(): ?RemoteAccessStatus
    {
        return $this->status;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function selectedPanel(): int
    {
        return $this->panel;
    }

    public function pendingConfirm(): ?string
    {
        return $this->pendingConfirm;
    }
}

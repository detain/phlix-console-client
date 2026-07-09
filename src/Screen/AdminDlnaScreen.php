<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\DlnaServerStatus;
use Phlix\Console\Msg\AdminDlnaActionDoneMsg;
use Phlix\Console\Msg\AdminDlnaActionFailedMsg;
use Phlix\Console\Msg\AdminDlnaFailedMsg;
use Phlix\Console\Msg\AdminDlnaStatusLoadedMsg;
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
 * The admin DLNA-server surface: a read-only status panel (Enabled · Running ·
 * Server ID · Friendly name · Port · Base URL · message) plus immediate
 * start/stop controls.
 *
 * `s` starts the server (offered only when it is not running) and `t` stops it
 * (offered only when it is running); each gate is derived from the current
 * status. Start/stop are immediate state changes, so there is no live poll — on
 * success the confirmation is toasted and the status refetched; on failure the
 * friendly server `message` is toasted (the failure bodies use `message`, not
 * `error`, and the {@see AdminClient} re-surfaces it). An auth failure surfaces a
 * session expiry. `r` refreshes.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded status, busy flag, and error are private mutable view state set via
 * clone-mutate (the established screen idiom).
 */
final class AdminDlnaScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the DLNA server status.';

    private ?DlnaServerStatus $status = null;
    private bool $loaded = false;
    private ?string $error = null;

    /** A fetch / action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

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

        return Cmd::promise(static fn () => $admin->dlnaStatus()->then(
            static fn (DlnaServerStatus $status): Msg => new AdminDlnaStatusLoadedMsg($status),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminDlnaFailedMsg(self::LOAD_FAILED),
        ));
    }

    /**
     * Build the command for a fired start/stop action: the action's promise
     * mapped to a done/failed Msg. On rejection the friendly server `message`
     * (re-surfaced by the client) is toasted.
     *
     * @param PromiseInterface<string> $promise
     */
    private function actionCmd(PromiseInterface $promise): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (string $message): Msg => new AdminDlnaActionDoneMsg($message),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminDlnaActionFailedMsg($e->getMessage()),
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
        if ($msg instanceof AdminDlnaStatusLoadedMsg) {
            return [$this->withStatus($msg->status), null];
        }
        if ($msg instanceof AdminDlnaFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminDlnaActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminDlnaActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin · DLNA', $this->body(), $this->hint(), $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
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

        // `s` starts only when not running; `t` stops only when running. The
        // server enforces this too, but gating the keys keeps the controls honest.
        if ($rune === 's' && !$status->running) {
            return [$this->working(), $this->actionCmd($this->admin->startDlna())];
        }
        if ($rune === 't' && $status->running) {
            return [$this->working(), $this->actionCmd($this->admin->stopDlna())];
        }

        return [$this, null];
    }

    // ---- action results ------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function onActionDone(AdminDlnaActionDoneMsg $msg): array
    {
        // Start/stop are immediate; just refetch the status so the change shows.
        return [$this->working(), Cmd::batch(Cmd::send(ShowToastMsg::success($msg->message)), $this->fetchCmd())];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;
        $next->busy = true;

        return [$next, $next->fetchCmd()];
    }

    // ---- clone-mutate copies -------------------------------------------

    private function withStatus(DlnaServerStatus $status): self
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
            return "\n  Loading DLNA server status…";
        }

        $lines = [
            '',
            '  State:         ' . $status->stateLabel(),
            '  Enabled:       ' . ($status->enabled ? 'yes' : 'no'),
            '  Running:       ' . ($status->running ? 'yes' : 'no'),
            '  Server ID:     ' . ($status->serverId ?? '—'),
            '  Friendly name: ' . ($status->friendlyName ?? '—'),
            '  Port:          ' . ($status->port !== null ? (string) $status->port : '—'),
            '  Base URL:      ' . ($status->baseUrl ?? '—'),
        ];
        if ($status->message !== null) {
            $lines[] = '  Note:          ' . $status->message;
        }
        $lines[] = '';
        $lines[] = '  ' . $this->statusLine($status);

        return implode("\n", $lines);
    }

    /** A line under the panel: the busy note, else a contextual control hint. */
    private function statusLine(DlnaServerStatus $status): string
    {
        if ($this->busy) {
            return 'Working…';
        }
        if ($status->running) {
            return 'The server is running. Press t to stop it.';
        }

        return 'The server is stopped. Press s to start it.';
    }

    /** The frame hint reflects which action is sensible for the current status. */
    private function hint(): string
    {
        $status = $this->status;
        if ($status === null || $this->error !== null) {
            return 'r refresh   Esc back';
        }

        $action = $status->running ? 't stop' : 's start';

        return $action . '   r refresh   Esc back';
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'DLNA';
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

    public function status(): ?DlnaServerStatus
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
}

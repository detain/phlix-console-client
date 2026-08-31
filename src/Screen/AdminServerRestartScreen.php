<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\AdminServerRestartDoneMsg;
use Phlix\Console\Msg\AdminServerRestartFailedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Sprinkles\Style;

/**
 * The admin Server Restart screen: triggers a graceful worker reload and
 * confirms it through the server's own ack.
 *
 * ACK-BEFORE-SIGNAL (`AdminRestartController.php:38-41`): the server flushes
 * the JSON ack to the socket BEFORE the deferred SIGUSR2 cycles the worker
 * handling this very request, and the master never dies — the connection
 * therefore stays valid across the reload and the ack IS the completion
 * signal.
 *
 * There is deliberately NO status panel and NO post-restart poll: the
 * pre-S405 init/poll rode `/api/v1/admin/server/status`, a rail the server
 * never registered, so the old poll could only ever burn 10×2s of timers and
 * then fail. The deferred SIGUSR2 re-forks workers while the master survives;
 * a resolved restart promise means the reload is scheduled and underway, which
 * is everything the client can truthfully know.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * ack + flags are private mutable view state set via clone-mutate (the
 * established screen idiom).
 */
final class AdminServerRestartScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const RESTART_FAILED = 'Server restart failed.';
    private const RESTART_CONFIRM = 'Type "restart" and press Enter to confirm';
    private const HINT = 'r  restart server      Esc  back';

    /** The server's ack message once the restart resolves ('' never; null = no ack yet). */
    private ?string $ack = null;
    private ?string $error = null;
    private bool $confirming = false;
    private bool $restarting = false;
    /** The characters typed during restart confirmation. */
    private string $typed = '';
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    /**
     * No startup fetch: the screen shows static guidance until the operator
     * acts (the pre-S405 init polled a rail that never existed server-side).
     */
    public function init(): ?\Closure
    {
        return null;
    }

    private function restartCmd(): \Closure
    {
        return function (): \React\Promise\PromiseInterface {
            return $this->admin->restartServer()->then(
                static fn (string $message): Msg => new AdminServerRestartDoneMsg($message),
                static function (\Throwable $e): \React\Promise\PromiseInterface {
                    return \React\Promise\resolve(
                        $e instanceof AuthError
                            ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                            : new AdminServerRestartFailedMsg(self::RESTART_FAILED . ' ' . $e->getMessage()),
                    );
                },
            );
        };
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminServerRestartDoneMsg) {
            return [$this->withDone($msg->message), null];
        }
        if ($msg instanceof AdminServerRestartFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin · Server Restart', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        // During confirmation, handle all keys via typed-confirm handler
        if ($this->confirming) {
            return $this->handleConfirmKey($msg);
        }

        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return $this->handleRestart();
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleRestart(): array
    {
        if ($this->restarting) {
            return [$this, null];
        }

        // First 'r' press - enter confirmation mode
        return [$this->withConfirming(), null];
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg): array
    {
        // Escape cancels confirmation
        if ($msg->type === KeyType::Escape) {
            return [$this->withConfirming(false)->withTyped(''), null];
        }

        // Enter submits the typed confirmation
        if ($msg->type === KeyType::Enter) {
            if ($this->typed === 'restart') {
                $next = clone $this;
                $next->confirming = false;
                $next->restarting = true;
                $next->error = null;
                $next->ack = null;

                return [$next, $this->restartCmd()];
            }

            return [$this->withTyped(''), Cmd::send(ShowToastMsg::error('Type "restart" to confirm'))];
        }

        // Accumulate character input (up to 10 chars)
        if ($msg->type === KeyType::Char && strlen($this->typed) < 10) {
            return [$this->withTyped($this->typed . $msg->rune), null];
        }

        return [$this, null];
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if ($this->confirming) {
            return "\n  " . self::RESTART_CONFIRM . " {$this->typed}\n\n  This will interrupt all active streams.";
        }
        if ($this->restarting || $this->ack !== null) {
            $ack = $this->ack ?? 'Restart signal sent.';

            return "\n  {$ack}\n\n  The server acknowledged the restart signal. Workers are cycling now; the master itself never stopped.\n\n  Press Esc to close this screen.";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }

        $accent = Style::new()->bold();
        $lines = [
            '',
            '  ' . $accent->render('Press r') . ' to restart the server.',
            '  This will interrupt all active streams.',
            '  A graceful reload re-forks the workers after in-flight requests drain.',
        ];

        return implode("\n", $lines);
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withDone(string $message): self
    {
        $next = clone $this;
        $next->ack = $message;
        $next->error = null;
        $next->restarting = false;
        $next->confirming = false;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->restarting = false;

        return $next;
    }

    private function withConfirming(bool $confirming = true): self
    {
        $next = clone $this;
        $next->confirming = $confirming;

        return $next;
    }

    private function withTyped(string $typed): self
    {
        $next = clone $this;
        $next->typed = $typed;

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
        return 'Server Restart';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function error(): ?string
    {
        return $this->error;
    }

    public function isConfirming(): bool
    {
        return $this->confirming;
    }

    public function isRestarting(): bool
    {
        return $this->restarting;
    }
}

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
 * The admin Server Restart screen: shows server restart options and confirmation.
 * The screen displays the current server status and provides a 'r' key to trigger
 * a restart with a confirmation prompt. After restart is triggered, it shows a
 * waiting state while polling for the server to come back up.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data + flags are private mutable view state set via clone-mutate (the
 * established screen idiom).
 */
final class AdminServerRestartScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const RESTART_FAILED = 'Server restart failed.';
    private const RESTART_CONFIRM = 'Press r again to confirm restart…';
    private const RESTARTING = 'Server is restarting, waiting for it to come back up…';
    private const HINT = 'r  restart server      Esc  back';

    private bool $loaded = false;
    private ?string $error = null;
    private bool $confirming = false;
    private bool $restarting = false;
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
        return $this->fetchStatusCmd();
    }

    private function fetchStatusCmd(): \Closure
    {
        return Cmd::promise(fn () => $this->admin->serverStatus()->then(
            fn (int $uptime): Msg => new AdminServerRestartDoneMsg('Server is running. Uptime: ' . self::formatUptime($uptime) . 's'),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminServerRestartFailedMsg('Server status check failed.'),
        ));
    }

    private function restartCmd(): \Closure
    {
        return Cmd::promise(fn () => $this->admin->restartServer()->then(
            static fn (string $message): Msg => new AdminServerRestartDoneMsg($message),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminServerRestartFailedMsg(self::RESTART_FAILED . ' ' . $e->getMessage()),
        ));
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
        if (!$this->confirming) {
            return [$this->withConfirming(), null];
        }

        // Second 'r' press - actually trigger the restart
        $next = clone $this;
        $next->confirming = false;
        $next->restarting = true;
        $next->error = null;

        return [$next, $this->restartCmd()];
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if ($this->restarting) {
            return "\n  " . self::RESTARTING . "\n\n  Press Esc to close this screen.";
        }
        if ($this->confirming) {
            return "\n  " . self::RESTART_CONFIRM . "\n\n  This will interrupt all active streams.";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }
        if (!$this->loaded) {
            return "\n  Loading server status…";
        }

        $accent = Style::new()->bold();
        $lines = [
            '',
            '  Server Status',
            '',
            '  ' . $accent->render('✓') . ' Server is running',
            '',
            '  Press r to restart the server.',
            '  This will interrupt all active streams.',
        ];

        return implode("\n", $lines);
    }

    private static function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return (string) $seconds;
        }
        if ($seconds < 3600) {
            $mins = (int) floor($seconds / 60);

            return $mins . 'm';
        }
        $hours = (int) floor($seconds / 3600);
        $mins = (int) floor(($seconds % 3600) / 60);

        return $hours . 'h ' . $mins . 'm';
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    private function withDone(string $message): self
    {
        $next = clone $this;
        $next->loaded = true;
        $next->error = null;
        $next->restarting = false;
        $next->confirming = false;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = true;
        $next->restarting = false;

        return $next;
    }

    private function withConfirming(): self
    {
        $next = clone $this;
        $next->confirming = true;

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

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

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

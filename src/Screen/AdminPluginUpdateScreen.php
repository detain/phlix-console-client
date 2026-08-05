<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\AdminPluginUpdateActionDoneMsg;
use Phlix\Console\Msg\AdminPluginUpdateActionFailedMsg;
use Phlix\Console\Msg\AdminPluginUpdateFailedMsg;
use Phlix\Console\Msg\AdminPluginUpdateLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The admin Plugin Updates surface: shows available plugin updates, lets the
 * admin toggle auto-update, set the catalog channel, and apply updates after
 * an "update" confirmation.
 *
 * The client is injected (built locally by the App from its shared ApiClient,
 * so the App holds no AdminClient field). Stable collaborators are readonly;
 * the loaded updates and mutable view state are set via clone-mutate (the
 * established screen idiom).
 */
final class AdminPluginUpdateScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load plugin updates.';
    private const HINT = 'a  toggle auto-update   c  set channel   u  apply updates   Esc  back';

    /** @var list<array{name:string,current_version:string,latest_version:string}> */
    private array $updates = [];
    private bool $loaded = false;
    private ?string $error = null;
    private bool $autoUpdate = false;
    private string $channel = 'stable';
    private ?string $pendingApply = null;
    private string $typed = '';

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
        return Cmd::promise(function (): \React\Promise\PromiseInterface {
            return \React\Promise\all([
                $this->admin->pluginUpdates(),
                $this->admin->pluginAutoUpdate(),
                $this->admin->pluginCatalogChannel(),
            ])->then(
                /** @param array<int, mixed> $results */
                static function (array $results): Msg {
                    /** @var list<array{name:string,current_version:string,latest_version:string}> $updates */
                    $updates = $results[0];
                    /** @var bool $autoUpdate */
                    $autoUpdate = $results[1];
                    /** @var string $channel */
                    $channel = $results[2];

                    return new AdminPluginUpdateLoadedMsg($channel, $autoUpdate, $updates);
                },
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new AdminPluginUpdateFailedMsg(self::LOAD_FAILED),
            );
        });
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
        if ($msg instanceof AdminPluginUpdateLoadedMsg) {
            return [$this->withUpdates($msg), null];
        }
        if ($msg instanceof AdminPluginUpdateFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminPluginUpdateActionDoneMsg) {
            return [$this->idle(), Cmd::batch(
                Cmd::send(ShowToastMsg::success($msg->message)),
                $this->fetchCmd(),
            )];
        }
        if ($msg instanceof AdminPluginUpdateActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    // ---- key handling -------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        $key = $msg->string();

        if ($key === 'q' || $key === 'Escape') {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        if ($this->pendingApply !== null) {
            return $this->handleConfirmKey($msg);
        }

        if ($key === 'u') {
            return [$this->armApply(), null];
        }

        if ($key === 'a') {
            return [$this, $this->toggleAutoUpdateCmd()];
        }

        if ($key === 'c') {
            return [$this, $this->setChannelCmd()];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg): array
    {
        if ($msg->string() === 'Enter') {
            if ($this->typed === 'update') {
                return $this->doApplyUpdates();
            }

            return [$this->withError('Type "update" to confirm')->withPendingApply(null)->withTyped(''), null];
        }
        if ($msg->string() === 'Escape') {
            return [$this->withPendingApply(null)->withTyped(''), null];
        }
        $newTyped = strlen($this->typed) < 6 ? $this->typed . $msg->rune : $this->typed;

        return [$this->withTyped($newTyped), null];
    }

    // ---- actions ------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function doApplyUpdates(): array
    {
        return [
            $this->withPendingApply(null)->withTyped(''),
            $this->applyUpdatesCmd(),
        ];
    }

    private function applyUpdatesCmd(): \Closure
    {
        return Cmd::promise(function (): \React\Promise\PromiseInterface {
            return $this->admin->applyPluginUpdates()->then(
                static fn (): Msg => new AdminPluginUpdateActionDoneMsg('Plugin updates applied'),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new AdminPluginUpdateActionFailedMsg('Failed: ' . $e->getMessage()),
            );
        });
    }

    private function toggleAutoUpdateCmd(): \Closure
    {
        $autoUpdate = $this->autoUpdate;

        return Cmd::promise(function () use ($autoUpdate): \React\Promise\PromiseInterface {
            return $this->admin->setPluginAutoUpdate(!$autoUpdate)->then(
                static fn (): Msg => new AdminPluginUpdateActionDoneMsg(
                    'Auto-update ' . (!$autoUpdate ? 'enabled' : 'disabled'),
                ),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new AdminPluginUpdateActionFailedMsg('Failed: ' . $e->getMessage()),
            );
        });
    }

    private function setChannelCmd(): \Closure
    {
        $channel = $this->channel;

        return Cmd::promise(function () use ($channel): \React\Promise\PromiseInterface {
            return $this->admin->setPluginCatalogChannel($channel)->then(
                static fn (): Msg => new AdminPluginUpdateActionDoneMsg('Channel set to ' . $channel),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new AdminPluginUpdateActionFailedMsg('Failed: ' . $e->getMessage()),
            );
        });
    }

    // ---- clone-mutate -------------------------------------------------

    private function withUpdates(AdminPluginUpdateLoadedMsg $msg): self
    {
        $next = clone $this;
        $next->updates = $msg->updates;
        $next->autoUpdate = $msg->autoUpdate;
        $next->channel = $msg->channel;
        $next->loaded = true;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;

        return $next;
    }

    private function withPendingApply(?string $pending): self
    {
        $next = clone $this;
        $next->pendingApply = $pending;

        return $next;
    }

    private function withTyped(string $typed): self
    {
        $next = clone $this;
        $next->typed = $typed;

        return $next;
    }

    private function armApply(): self
    {
        return $this->withPendingApply('armed');
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    private function idle(): self
    {
        return $this;
    }

    // ---- Breadcrumbed --------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Plugin Updates';
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- Themed --------------------------------------------------------

    public function view(): string
    {
        return Chrome::frame('Admin · Plugin Updates', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- render --------------------------------------------------------

    private function body(): string
    {
        $out = '';

        if ($this->error !== null) {
            $out .= "  {$this->error}\n\n";
        } elseif (!$this->loaded) {
            $out .= "  Loading plugin updates…\n\n";
        } elseif ($this->updates === []) {
            $out .= "  No plugin updates available.\n\n";
        } else {
            $out .= "  Available updates:\n\n";
            foreach ($this->updates as $update) {
                $name = $update['name'];
                $current = $update['current_version'];
                $latest = $update['latest_version'];
                $out .= "  {$name}: {$current} → {$latest}\n";
            }
            $out .= "\n";
        }

        $status = 'Auto-update: ' . ($this->autoUpdate ? 'ON' : 'OFF') . '   Channel: ' . $this->channel;
        $out .= "  {$status}\n";

        if ($this->pendingApply !== null) {
            $out .= '  Type "update" to confirm: ' . $this->typed . "\n";
        }

        return $out;
    }
}

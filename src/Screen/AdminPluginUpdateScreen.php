<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\I18n\ExceptionSentences;
use Phlix\Console\I18n\Lang;
use Phlix\Console\Msg\AdminPluginUpdateActionDoneMsg;
use Phlix\Console\Msg\AdminPluginUpdateActionFailedMsg;
use Phlix\Console\Msg\AdminPluginUpdateFailedMsg;
use Phlix\Console\Msg\AdminPluginUpdateLoadedMsg;
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

/**
 * The admin Plugin Updates surface: shows available plugin updates, lets the
 * admin toggle auto-update, choose the catalog channel from the two the
 * server accepts (`stable` | `dev` — PUT /api/v1/admin/plugins/catalog/
 * channel), and apply updates after an "update" confirmation.
 *
 * `c` arms an inline picker instead of firing a request [review
 * console__a11y, CU-008]: pressing it used to PUT the ALREADY-LOADED channel
 * back — theater dressed as a control. The choice (s/d) is what ships;
 * Escape cancels in place.
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
    use ResizesTo;

    private const SESSION_EXPIRED_KEY = 'auth.session_expired';
    private const LOAD_FAILED = 'Could not load plugin updates.';
    private const HINT = 'a  toggle auto-update      c  set channel      u  apply updates      Esc  back';

    /** The two channels the server's channel endpoint accepts. */
    private const CHANNEL_STABLE = 'stable';
    private const CHANNEL_DEV = 'dev';

    /** @var list<array{name:string,current_version:string,latest_version:string}> */
    private array $updates = [];
    private bool $loaded = false;
    private ?string $error = null;
    private bool $autoUpdate = false;
    private string $channel = 'stable';
    private ?string $pendingApply = null;
    private ?string $pendingChannel = null;
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
                    ? new SessionExpiredMsg(Lang::t(self::SESSION_EXPIRED_KEY))
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
        // The channel picker is modal while armed — Escape cancels it in
        // place, so this arm outranks the back-nav below [CU-008].
        if ($this->pendingChannel !== null) {
            return $this->handleChannelKey($msg);
        }

        // The typed-confirm is modal too and outranks back-nav (the
        // PlaylistsScreen family's ordering): named keys belong to the
        // confirm while it is armed. [CU-008 fold] The old arms compared
        // $msg->string() against 'Escape'/'Enter' — KeyType's values are
        // LOWERCASE, so every named key here was dead: after typing the
        // confirm word the screen dead-locked (Enter never applied, Escape
        // never cancelled, q was capped out of the buffer).
        if ($this->pendingApply !== null) {
            return $this->handleConfirmKey($msg);
        }

        if ($msg->type === KeyType::Escape || $msg->string() === 'q') {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        $key = $msg->string();

        if ($key === 'u') {
            return [$this->armApply(), null];
        }

        if ($key === 'a') {
            return [$this, $this->toggleAutoUpdateCmd()];
        }

        if ($key === 'c') {
            return [$this->withPendingChannel('armed'), null];
        }

        return [$this, null];
    }

    /**
     * The two-option channel picker: `s` ships stable, `d` ships dev, Escape
     * cancels — every other key is swallowed while the picker is open.
     *
     * @return array{self, ?\Closure}
     */
    private function handleChannelKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this->withPendingChannel(null), null];
        }

        // Modifier-free selection only [CONSOLIDATED M16 doctrine]: ctrl/alt
        // chords carry commands, never channel choices.
        if ($msg->type !== KeyType::Char || $msg->ctrl || $msg->alt || $msg->rune === '') {
            return [$this, null];
        }

        $channel = match ($msg->rune) {
            's' => self::CHANNEL_STABLE,
            'd' => self::CHANNEL_DEV,
            default => null,
        };
        if ($channel === null) {
            return [$this, null];
        }

        return [$this->withPendingChannel(null), $this->setChannelCmd($channel)];
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Enter) {
            if ($this->typed === 'update') {
                return $this->doApplyUpdates();
            }

            return [$this->withError("Type 'update' and press Enter to confirm")->withPendingApply(null)->withTyped(''), null];
        }
        if ($msg->type === KeyType::Escape) {
            return [$this->withPendingApply(null)->withTyped(''), null];
        }

        // Modifier-free typing only [review console__tuispec, M-CONSOLE-65 / CONSOLIDATED M16]:
        // ctrl/alt combos carry commands, not text — and the confirm word has
        // no spaces, so a Space-type key falls through like any other command.
        if ($msg->type !== KeyType::Char || $msg->ctrl || $msg->alt || $msg->rune === '') {
            return [$this, null];
        }

        $newTyped = mb_strlen($this->typed, 'UTF-8') < 6 ? $this->typed . $msg->rune : $this->typed;

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
                    ? new SessionExpiredMsg(Lang::t(self::SESSION_EXPIRED_KEY))
                    : new AdminPluginUpdateActionFailedMsg(ExceptionSentences::composed('Failed', $e, 'admin-plugin-update')),
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
                    ? new SessionExpiredMsg(Lang::t(self::SESSION_EXPIRED_KEY))
                    : new AdminPluginUpdateActionFailedMsg(ExceptionSentences::composed('Failed', $e, 'admin-plugin-update')),
            );
        });
    }

    private function setChannelCmd(string $channel): \Closure
    {
        return Cmd::promise(function () use ($channel): \React\Promise\PromiseInterface {
            return $this->admin->setPluginCatalogChannel($channel)->then(
                static fn (): Msg => new AdminPluginUpdateActionDoneMsg('Channel set to ' . $channel),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(Lang::t(self::SESSION_EXPIRED_KEY))
                    : new AdminPluginUpdateActionFailedMsg(ExceptionSentences::composed('Failed', $e, 'admin-plugin-update')),
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

    private function withPendingChannel(?string $pending): self
    {
        $next = clone $this;
        $next->pendingChannel = $pending;

        return $next;
    }

    private function armApply(): self
    {
        return $this->withPendingApply('armed');
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

        if ($this->pendingChannel !== null) {
            $out .= "\n  Channel — s  " . self::CHANNEL_STABLE . '   d  ' . self::CHANNEL_DEV . "   (current: {$this->channel})   Esc  cancel\n";
        }

        if ($this->pendingApply !== null) {
            $out .= "  ⚠ Plugin updates can destroy settings including OAuth tokens (Trakt, Last.fm, etc.)\n\n";
            $out .= "  Type 'update' and press Enter to confirm: " . $this->typed . "\n";
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\Plugin;
use Phlix\Console\Msg\AdminPluginActionDoneMsg;
use Phlix\Console\Msg\AdminPluginActionFailedMsg;
use Phlix\Console\Msg\AdminPluginsFailedMsg;
use Phlix\Console\Msg\AdminPluginsLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenAdminPluginDetailMsg;
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
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;

/**
 * The admin Plugins surface: a windowed {@see Table} of every installed plugin
 * (Name · Version · Type · Enabled · Signed) driven by per-row lifecycle actions.
 *
 * Row actions operate on the selected plugin: `e` toggles enable/disable (calling
 * enable or disable per the current state), `x` uninstalls (an inline y/n confirm
 * on the status line — the same arm-then-y mechanism the Users surface uses),
 * `r` refresh. `i` opens an inline candy-forms text input for an install URL; on
 * submit it installs the plugin, on Esc it cancels. On success the action's
 * message is toasted and the list refetched; on failure the server `error` is
 * toasted (e.g. "not HTTPS" / "signature invalid") and the list left unchanged;
 * an auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data, selection, busy flag, the pending uninstall, and the embedded
 * install form are private mutable view state set via clone-mutate (the
 * established screen idiom).
 *
 * SCOPE: list + enable/disable/uninstall + install-from-URL only. The catalog
 * browser, the settings-schema editor, and the plugin detail page are deferred.
 */
final class AdminPluginsScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the plugins.';
    private const HINT = 'e enable/disable   D detail/settings   x uninstall   i install URL   r refresh   Esc back';
    private const INSTALL_HINT = 'Enter  install      Esc  cancel';

    /** @var list<Plugin> */
    private array $plugins = [];
    private bool $loaded = false;
    private ?string $error = null;

    private int $selected = 0;

    /** A fetch / action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

    /** An armed uninstall awaiting a y/n confirm, or null when none is pending. */
    private ?Plugin $pendingUninstall = null;

    /** The embedded install-URL form while the install input is open, else null. */
    private ?Form $installForm = null;

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
        return Cmd::promise(fn () => $this->admin->plugins()->then(
            /** @param list<Plugin> $plugins */
            static fn (array $plugins): Msg => new AdminPluginsLoadedMsg($plugins),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminPluginsFailedMsg(self::LOAD_FAILED),
        ));
    }

    /**
     * Build the command for a fired action: the action's promise mapped to a
     * done/failed Msg with the given success message.
     *
     * @param PromiseInterface<mixed> $promise
     */
    private function actionCmd(PromiseInterface $promise, string $success): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (mixed $_): Msg => new AdminPluginActionDoneMsg($success),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminPluginActionFailedMsg($e->getMessage()),
        ));
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        // While the install input is open it captures all keys.
        if ($this->installForm !== null) {
            return $this->updateInstall($msg, $this->installForm);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminPluginsLoadedMsg) {
            return [$this->withPlugins($msg->plugins), null];
        }
        if ($msg instanceof AdminPluginsFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminPluginActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminPluginActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->installForm !== null) {
            return Chrome::frame('Admin · Plugins · Install', $this->installBody($this->installForm), self::INSTALL_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        return Chrome::frame('Admin · Plugins', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // An armed uninstall captures y/n/Esc before anything else.
        if ($this->pendingUninstall !== null) {
            return $this->handleConfirmKey($msg, $this->pendingUninstall);
        }

        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
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
        if ($rune === 'i') {
            return [$this->openInstall(), null];
        }

        // The remaining keys are per-row actions; they need a selected plugin and
        // an idle screen.
        if ($this->busy) {
            return [$this, null];
        }
        $plugin = $this->selectedPlugin();
        if ($plugin === null) {
            return [$this, null];
        }

        return match ($rune) {
            'e' => $this->toggleEnable($plugin),
            'D' => [$this, Cmd::send(new OpenAdminPluginDetailMsg($plugin->name))],
            'x' => [$this->arm($plugin), null],
            default => [$this, null],
        };
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg, Plugin $plugin): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            return [$this->working(), $this->actionCmd(
                $this->admin->uninstallPlugin($plugin->name),
                "Plugin '{$plugin->name}' uninstalled",
            )];
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'n')) {
            return [$this->cancelPending(), null];
        }

        return [$this, null];
    }

    // ---- install input (embedded candy-forms) --------------------------

    /**
     * Drive the embedded install form. candy-forms' Form returns Cmd::quit() on
     * submit/abort (it's built to run standalone); we intercept that and
     * substitute our own intent: a non-empty submitted URL installs the plugin, an
     * empty one re-opens the input with an error, and an abort cancels.
     *
     * @return array{self, ?\Closure}
     */
    private function updateInstall(Msg $msg, Form $form): array
    {
        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update returns Model's loose `:array`; narrow it. */
        $result = $form->update($msg);
        [$next, $cmd] = $result;

        if ($next->isAborted()) {
            return [$this->closeInstall(), null];
        }

        if ($next->isSubmitted()) {
            $url = trim($next->getString('url'));
            if ($url === '') {
                // Re-open a fresh input rather than installing an empty URL.
                $fresh = self::buildInstallForm();

                return [$this->withInstallForm($fresh), Cmd::batch(Cmd::send(ShowToastMsg::error('Enter a plugin URL.')), $fresh->init())];
            }

            return [$this->closeInstall()->working(), $this->actionCmd(
                $this->admin->installPlugin($url),
                'Plugin installed',
            )];
        }

        return [$this->withInstallForm($next), $cmd];
    }

    private static function buildInstallForm(): Form
    {
        return Form::new(
            Input::new('url')
                ->withTitle('Plugin URL')
                ->withPlaceholder('https://github.com/owner/repo')
                ->required(),
        );
    }

    // ---- actions -------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function toggleEnable(Plugin $plugin): array
    {
        $promise = $plugin->enabled
            ? $this->admin->disablePlugin($plugin->name)
            : $this->admin->enablePlugin($plugin->name);
        $success = $plugin->enabled
            ? "Plugin '{$plugin->name}' disabled"
            : "Plugin '{$plugin->name}' enabled";

        return [$this->working(), $this->actionCmd($promise, $success)];
    }

    /** @return array{self, ?\Closure} */
    private function onActionDone(AdminPluginActionDoneMsg $msg): array
    {
        // Refetch the list so the change (enabled state / install / removal) shows.
        return [$this->working(), Cmd::batch(Cmd::send(ShowToastMsg::success($msg->message)), $this->fetchCmd())];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;
        $next->pendingUninstall = null;

        return [$next, $next->fetchCmd()];
    }

    // ---- clone-mutate copies -------------------------------------------

    /** @param list<Plugin> $plugins */
    private function withPlugins(array $plugins): self
    {
        $next = clone $this;
        $next->plugins = $plugins;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;
        $next->pendingUninstall = null;
        $next->selected = $plugins === [] ? 0 : min($this->selected, count($plugins) - 1);

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = false;
        $next->busy = false;
        $next->pendingUninstall = null;

        return $next;
    }

    /** Enter the busy (in-flight) state, clearing any armed confirm. */
    private function working(): self
    {
        $next = clone $this;
        $next->busy = true;
        $next->pendingUninstall = null;

        return $next;
    }

    /** Leave the busy state (after a failed action) without touching the list. */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;
        $next->pendingUninstall = null;

        return $next;
    }

    private function arm(Plugin $plugin): self
    {
        $next = clone $this;
        $next->pendingUninstall = $plugin;

        return $next;
    }

    private function cancelPending(): self
    {
        $next = clone $this;
        $next->pendingUninstall = null;

        return $next;
    }

    private function openInstall(): self
    {
        return $this->withInstallForm(self::buildInstallForm());
    }

    private function closeInstall(): self
    {
        return $this->withInstallForm(null);
    }

    private function withInstallForm(?Form $form): self
    {
        $next = clone $this;
        $next->installForm = $form;
        $next->pendingUninstall = null;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->plugins);
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
        if (!$this->loaded && $this->error === null) {
            return "\n  Loading plugins…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }
        if ($this->plugins === []) {
            return "\n  No plugins installed.\n\n" . $this->statusLine();
        }

        $rows = [];
        foreach ($this->plugins as $plugin) {
            $rows[] = [
                $plugin->name,
                $plugin->version === '' ? '—' : $plugin->version,
                $plugin->type === '' ? '—' : $plugin->type,
                $plugin->enabled ? '✓' : '–',
                $plugin->signed ? '✓' : '–',
            ];
        }

        $table = Table::render([
            ['title' => 'Name', 'width' => 0],
            ['title' => 'Version', 'width' => 14],
            ['title' => 'Type', 'width' => 14],
            ['title' => 'Enabled', 'width' => 9, 'align' => 'right'],
            ['title' => 'Signed', 'width' => 8, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());

        return "\n" . $this->headerLine() . "\n" . $table . "\n\n" . $this->statusLine();
    }

    /** The header: the installed-plugin count. */
    private function headerLine(): string
    {
        return '  Installed: ' . $this->countLabel();
    }

    private function countLabel(): string
    {
        $count = count($this->plugins);

        return $count === 1 ? '1 plugin' : "{$count} plugins";
    }

    /**
     * The status line under the table: the armed uninstall prompt when one is
     * pending, else the busy note, else a hint.
     */
    private function statusLine(): string
    {
        $pending = $this->pendingUninstall;
        if ($pending !== null) {
            return "  Uninstall plugin '{$pending->name}'? (y/n)";
        }
        if ($this->busy) {
            return '  Working…';
        }

        return '  select a plugin and press an action key, or i to install from a URL.';
    }

    private function installBody(Form $form): string
    {
        $lines = ['Install a plugin from an https:// archive or repository URL.', ''];

        return implode("\n", $lines) . $form->view();
    }

    private function viewportRows(): int
    {
        // The frame body holds the header line, then the table (header + rule = 2
        // extra rows), then a blank line + the status line. Window the data rows to
        // the body height less those chrome rows so the selected row is never clipped.
        return max(1, Chrome::bodyHeight($this->rows) - 5);
    }

    private function selectedPlugin(): ?Plugin
    {
        return $this->plugins[$this->selected] ?? null;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Plugins';
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

    /** @return list<Plugin> */
    public function pluginList(): array
    {
        return $this->plugins;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function pendingUninstall(): ?Plugin
    {
        return $this->pendingUninstall;
    }

    public function isInstalling(): bool
    {
        return $this->installForm !== null;
    }
}

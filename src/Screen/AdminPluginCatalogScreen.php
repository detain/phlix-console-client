<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\CatalogPlugin;
use Phlix\Console\Api\Dto\Admin\PluginCatalogResult;
use Phlix\Console\Msg\AdminPluginCatalogActionDoneMsg;
use Phlix\Console\Msg\AdminPluginCatalogActionFailedMsg;
use Phlix\Console\Msg\AdminPluginCatalogFailedMsg;
use Phlix\Console\Msg\AdminPluginCatalogLoadedMsg;
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
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;

/**
 * The admin Plugin-Catalog surface: a windowed {@see Table} of every catalog
 * entry across the configured sources (Title · Type · Author · Installed · Enabled)
 * over a header listing the source(s) and any per-source fetch `errors`
 * (non-fatal). Pushed from the AdminPluginsScreen (`C`).
 *
 * Row actions on the selected entry:
 *   - **Enter / `i`** on a NOT-installed entry → install via its **`repo`** field
 *     (the install URL — there is no `url`/`version` on a catalog entry) behind an
 *     inline `y/n` confirm (it installs third-party code); on success the entry's
 *     catalog is refetched (so it flips to installed). On an ALREADY-installed entry
 *     it just shows an info toast and fires no request.
 *   - **`a`** add a catalog source: an embedded candy-forms {@see Input} for the URL
 *     (the candy-forms quit-intercept keeps the embedded `Cmd::quit()` from exiting
 *     the app); on submit it POSTs and refetches, on Esc it cancels.
 *   - **`x`** remove the source of the selected entry's catalog behind an inline
 *     `y/n` confirm → DELETE → refetch.
 *   - **`r`** refresh; **↑/↓** select; **Esc/`q`** → NavigateBack.
 *
 * On any action failure the server `error` is toasted and the catalog is left
 * unchanged; an auth failure surfaces a session expiry. The client is injected
 * (built locally by the App from its shared ApiClient, so the App holds no
 * AdminClient field). Stable collaborators are readonly; the loaded result,
 * selection, busy flag, the two pending confirms, and the embedded add-source form
 * are private mutable view state set via clone-mutate (the established idiom).
 */
final class AdminPluginCatalogScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the plugin catalog.';
    private const HINT = '↑↓ select   ⏎/i install   a add source   x remove source   r refresh   Esc back';
    private const ADD_HINT = 'Enter  add      Esc  cancel';

    /**
     * The loaded catalog. Empty until the first {@see AdminPluginCatalogLoadedMsg}
     * (use {@see $loaded} to tell "not yet" from "genuinely empty"), so the screen
     * never has to null-guard the result.
     */
    private PluginCatalogResult $result;
    private bool $loaded = false;
    private ?string $error = null;

    private int $selected = 0;

    /** A fetch / action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

    /** An armed install awaiting a y/n confirm (the entry to install), else null. */
    private ?CatalogPlugin $pendingInstall = null;

    /** An armed source removal awaiting a y/n confirm (the source URL), else null. */
    private ?string $pendingRemoveSource = null;

    /** The embedded add-source form while its input is open, else null. */
    private ?Form $sourceForm = null;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private int $cols = 80,
        private int $rows = 24,
    ) {
        $this->result = PluginCatalogResult::fromArray([]);
    }

    public function init(): \Closure
    {
        return $this->fetchCmd();
    }

    // ---- fetch ---------------------------------------------------------

    private function fetchCmd(): \Closure
    {
        return Cmd::promise(fn () => $this->admin->pluginCatalog()->then(
            static fn (PluginCatalogResult $result): Msg => new AdminPluginCatalogLoadedMsg($result),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminPluginCatalogFailedMsg(self::LOAD_FAILED),
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
            static fn (mixed $_): Msg => new AdminPluginCatalogActionDoneMsg($success),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminPluginCatalogActionFailedMsg($e->getMessage()),
        ));
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        // While the add-source input is open it captures all keys.
        if ($this->sourceForm !== null) {
            return $this->updateAddSource($msg, $this->sourceForm);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminPluginCatalogLoadedMsg) {
            return [$this->withResult($msg->result), null];
        }
        if ($msg instanceof AdminPluginCatalogFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminPluginCatalogActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminPluginCatalogActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->sourceForm !== null) {
            return Chrome::frame('Admin · Plugins · Catalog · Add source', $this->addBody($this->sourceForm), self::ADD_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        return Chrome::frame('Admin · Plugins · Catalog', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // An armed install captures y/n/Esc before anything else.
        if ($this->pendingInstall !== null) {
            return $this->handleInstallConfirm($msg, $this->pendingInstall);
        }
        // An armed source-removal captures y/n/Esc before anything else.
        if ($this->pendingRemoveSource !== null) {
            return $this->handleRemoveConfirm($msg, $this->pendingRemoveSource);
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
        if ($msg->type === KeyType::Enter) {
            return $this->beginInstall();
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
        if ($rune === 'a') {
            return [$this->openAddSource(), null];
        }
        if ($this->busy) {
            return [$this, null];
        }

        return match ($rune) {
            'i' => $this->beginInstall(),
            'x' => $this->beginRemoveSource(),
            default => [$this, null],
        };
    }

    /**
     * Begin installing the selected entry: an already-installed entry just shows an
     * info toast (no request); a not-installed entry arms a y/n confirm (it installs
     * third-party code).
     *
     * @return array{self, ?\Closure}
     */
    private function beginInstall(): array
    {
        if ($this->busy) {
            return [$this, null];
        }
        $entry = $this->selectedPlugin();
        if ($entry === null) {
            return [$this, null];
        }
        if ($entry->installed) {
            return [$this, Cmd::send(ShowToastMsg::info("'{$entry->displayTitle()}' is already installed."))];
        }

        return [$this->armInstall($entry), null];
    }

    /**
     * Begin removing the selected entry's catalog source: arms a y/n confirm. When
     * the source URL cannot be resolved (no catalog for the entry) it is a no-op.
     *
     * @return array{self, ?\Closure}
     */
    private function beginRemoveSource(): array
    {
        $source = $this->selectedSource();
        if ($source === null || $source === '') {
            return [$this, null];
        }

        return [$this->armRemoveSource($source), null];
    }

    /** @return array{self, ?\Closure} */
    private function handleInstallConfirm(KeyMsg $msg, CatalogPlugin $entry): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            return [$this->working(), $this->actionCmd(
                $this->admin->installPlugin($entry->repo),
                "Installing '{$entry->displayTitle()}'",
            )];
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'n')) {
            return [$this->cancelPending(), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleRemoveConfirm(KeyMsg $msg, string $source): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            return [$this->working(), $this->actionCmd(
                $this->admin->removeCatalogSource($source),
                "Removed source {$source}",
            )];
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'n')) {
            return [$this->cancelPending(), null];
        }

        return [$this, null];
    }

    // ---- add-source input (embedded candy-forms) -----------------------

    /**
     * Drive the embedded add-source form. candy-forms' Form returns Cmd::quit() on
     * submit/abort (it's built to run standalone); we intercept that and substitute
     * our own intent: a non-empty submitted URL adds the source, an empty one
     * re-opens the input with an error, and an abort cancels.
     *
     * @return array{self, ?\Closure}
     */
    private function updateAddSource(Msg $msg, Form $form): array
    {
        /** @var array{0: Form, 1: ?\Closure} $result candy-forms' Form::update returns Model's loose `:array`; narrow it. */
        $result = $form->update($msg);
        [$next, $cmd] = $result;

        if ($next->isAborted()) {
            return [$this->closeAddSource(), null];
        }

        if ($next->isSubmitted()) {
            $url = trim($next->getString('url'));
            if ($url === '') {
                $fresh = self::buildAddSourceForm();

                return [$this->withSourceForm($fresh), Cmd::batch(Cmd::send(ShowToastMsg::error('Enter a catalog source URL.')), $fresh->init())];
            }

            return [$this->closeAddSource()->working(), $this->actionCmd(
                $this->admin->addCatalogSource($url),
                "Added source {$url}",
            )];
        }

        return [$this->withSourceForm($next), $cmd];
    }

    private static function buildAddSourceForm(): Form
    {
        return Form::new(
            Input::new('url')
                ->withTitle('Catalog source URL')
                ->withPlaceholder('https://example.com/catalog.json')
                ->required(),
        );
    }

    // ---- post-action ---------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function onActionDone(AdminPluginCatalogActionDoneMsg $msg): array
    {
        // Refetch the catalog so the change (install / source add or remove) shows.
        return [$this->working(), Cmd::batch(Cmd::send(ShowToastMsg::success($msg->message)), $this->fetchCmd())];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;
        $next->pendingInstall = null;
        $next->pendingRemoveSource = null;

        return [$next, $next->fetchCmd()];
    }

    // ---- clone-mutate copies -------------------------------------------

    private function withResult(PluginCatalogResult $result): self
    {
        $next = clone $this;
        $next->result = $result;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;
        $next->pendingInstall = null;
        $next->pendingRemoveSource = null;
        $count = count($result->flatPlugins());
        $next->selected = $count === 0 ? 0 : min($this->selected, $count - 1);

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = false;
        $next->busy = false;
        $next->pendingInstall = null;
        $next->pendingRemoveSource = null;

        return $next;
    }

    /** Enter the busy (in-flight) state, clearing any armed confirm. */
    private function working(): self
    {
        $next = clone $this;
        $next->busy = true;
        $next->pendingInstall = null;
        $next->pendingRemoveSource = null;

        return $next;
    }

    /** Leave the busy state (after a failed action) without touching the catalog. */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;
        $next->pendingInstall = null;
        $next->pendingRemoveSource = null;

        return $next;
    }

    private function armInstall(CatalogPlugin $entry): self
    {
        $next = clone $this;
        $next->pendingInstall = $entry;
        $next->pendingRemoveSource = null;

        return $next;
    }

    private function armRemoveSource(string $source): self
    {
        $next = clone $this;
        $next->pendingRemoveSource = $source;
        $next->pendingInstall = null;

        return $next;
    }

    private function cancelPending(): self
    {
        $next = clone $this;
        $next->pendingInstall = null;
        $next->pendingRemoveSource = null;

        return $next;
    }

    private function openAddSource(): self
    {
        return $this->withSourceForm(self::buildAddSourceForm());
    }

    private function closeAddSource(): self
    {
        return $this->withSourceForm(null);
    }

    private function withSourceForm(?Form $form): self
    {
        $next = clone $this;
        $next->sourceForm = $form;
        $next->pendingInstall = null;
        $next->pendingRemoveSource = null;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->plugins());
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
            return "\n  Loading catalog…";
        }
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }

        $plugins = $this->result->flatPlugins();
        if ($plugins === []) {
            return "\n" . $this->headerLine($this->result) . "\n\n  No catalog entries.\n\n" . $this->statusLine();
        }

        $rows = [];
        foreach ($plugins as $entry) {
            $rows[] = [
                $entry->displayTitle(),
                $entry->type === '' ? '—' : $entry->type,
                $entry->author === '' ? '—' : $entry->author,
                $entry->installed ? '✓' : '–',
                $entry->enabled ? '✓' : '–',
            ];
        }

        $table = Table::render([
            ['title' => 'Title', 'width' => 0],
            ['title' => 'Type', 'width' => 12],
            ['title' => 'Author', 'width' => 18],
            ['title' => 'Installed', 'width' => 10, 'align' => 'right'],
            ['title' => 'Enabled', 'width' => 9, 'align' => 'right'],
        ], $rows, $this->selected, $this->cols - 4, $this->viewportRows());

        return "\n" . $this->headerLine($this->result) . "\n" . $table . "\n\n" . $this->statusLine();
    }

    /** The header: the configured source(s) + any per-source fetch errors. */
    private function headerLine(PluginCatalogResult $result): string
    {
        $sources = $result->sources === [] ? '(none)' : implode(', ', $result->sources);
        $line = '  Sources: ' . $sources;
        if ($result->errors !== []) {
            $line .= "\n  ! " . implode("\n  ! ", $result->errors);
        }

        return $line;
    }

    /**
     * The status line under the table: the armed confirm prompt when one is
     * pending, else the busy note, else a hint.
     */
    private function statusLine(): string
    {
        $install = $this->pendingInstall;
        if ($install !== null) {
            return "  Install '{$install->displayTitle()}' from {$install->repo}? (y/n)";
        }
        $remove = $this->pendingRemoveSource;
        if ($remove !== null) {
            return "  Remove catalog source {$remove}? (y/n)";
        }
        if ($this->busy) {
            return '  Working…';
        }

        return '  select an entry and press ⏎ to install, a to add a source.';
    }

    private function addBody(Form $form): string
    {
        $lines = ['Add a plugin catalog source (an https:// catalog manifest URL).', ''];

        return implode("\n", $lines) . $form->view();
    }

    private function viewportRows(): int
    {
        // The frame body holds the header line (1, +1 when errors), then the table
        // (header + rule = 2 extra rows), then a blank line + the status line. Window
        // the data rows to the body height less those chrome rows so the selected row
        // is never clipped.
        $errorRows = $this->result->errors !== [] ? count($this->result->errors) : 0;

        return max(1, Chrome::bodyHeight($this->rows) - 5 - $errorRows);
    }

    /** @return list<CatalogPlugin> */
    private function plugins(): array
    {
        return $this->result->flatPlugins();
    }

    private function selectedPlugin(): ?CatalogPlugin
    {
        return $this->plugins()[$this->selected] ?? null;
    }

    /**
     * The source URL of the catalog the selected entry belongs to, or null when no
     * entry is selected / its catalog cannot be found.
     */
    private function selectedSource(): ?string
    {
        // Walk the catalogs counting flattened entries until the selected flat index
        // is reached; the owning catalog's source is returned. Out of range (no
        // selectable entry) yields null.
        $index = 0;
        foreach ($this->result->catalogs as $catalog) {
            foreach ($catalog->plugins as $_plugin) {
                if ($index === $this->selected) {
                    return $catalog->source;
                }
                $index++;
            }
        }

        return null;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Catalog';
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

    public function result(): PluginCatalogResult
    {
        return $this->result;
    }

    /** @return list<CatalogPlugin> */
    public function pluginList(): array
    {
        return $this->plugins();
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

    public function pendingInstall(): ?CatalogPlugin
    {
        return $this->pendingInstall;
    }

    public function pendingRemoveSource(): ?string
    {
        return $this->pendingRemoveSource;
    }

    public function isAddingSource(): bool
    {
        return $this->sourceForm !== null;
    }
}

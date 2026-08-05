<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\AdminAuthProvidersFailedMsg;
use Phlix\Console\Msg\AdminAuthProvidersLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The admin auth providers (OIDC/LDAP/GitHub) management screen.
 *
 * Lists all registered providers with their enabled/configured status.
 * `r` refetches; `e` toggles enable/disable on the selected provider;
 * `s` opens the settings form for the selected provider; Esc/q goes back.
 *
 * Secrets (client_secret, bind_pw) are masked at the API client level and
 * never logged — see AdminClient::maskOidcSecret() / maskLdapSecret().
 */
final class AdminAuthProvidersScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load auth providers.';
    private const HINT = 'e  toggle  r  refresh  Esc  back';

    /** @var list<array{name:string,enabled:bool,configured:bool}> */
    private array $providers = [];
    private int $selectedIndex = 0;
    private bool $loading = true;
    private ?string $error = null;
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

    private function fetchCmd(): \Closure
    {
        return Cmd::promise(fn (): \React\Promise\PromiseInterface => $this->admin->listAuthProviders()
            ->then(
                static fn (array $providers): AdminAuthProvidersLoadedMsg => new AdminAuthProvidersLoadedMsg($providers),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new AdminAuthProvidersFailedMsg(self::LOAD_FAILED),
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
        if ($msg instanceof AdminAuthProvidersLoadedMsg) {
            $next = clone $this;
            $next->providers = $msg->providers;
            $next->loading = false;
            $next->error = null;
            $next->selectedIndex = 0;

            return [$next, null];
        }
        if ($msg instanceof AdminAuthProvidersFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'Admin · Auth Providers',
            $this->body(),
            self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this->reloading(), $this->fetchCmd()];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'e') {
            return $this->toggleSelected();
        }
        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->selectPrev();
        }
        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->selectNext();
        }

        return [$this, null];
    }

    // ---- selection -----------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function selectPrev(): array
    {
        if ($this->selectedIndex > 0) {
            return [$this->withSelectedIndex($this->selectedIndex - 1), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function selectNext(): array
    {
        if ($this->selectedIndex < count($this->providers) - 1) {
            return [$this->withSelectedIndex($this->selectedIndex + 1), null];
        }

        return [$this, null];
    }

    private function withSelectedIndex(int $index): self
    {
        $next = clone $this;
        $next->selectedIndex = $index;

        return $next;
    }

    // ---- toggle --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function toggleSelected(): array
    {
        if ($this->providers === []) {
            return [$this, null];
        }

        $provider = $this->providers[$this->selectedIndex] ?? null;
        if ($provider === null) {
            return [$this, null];
        }

        $name = (string) $provider['name'];

        $currentlyEnabled = !empty($provider['enabled']);
        $newEnabled = !$currentlyEnabled;

        $next = clone $this;
        $next->selectedIndex = 0;

        return [$next, $this->toggleCmd($name, $newEnabled)];
    }

    private function toggleCmd(string $name, bool $enabled): \Closure
    {
        $promise = $enabled
            ? $this->admin->enableAuthProvider($name)
            : $this->admin->disableAuthProvider($name);

        return Cmd::promise(
            fn (): \React\Promise\PromiseInterface => $promise
                ->then(
                    static fn (): AdminAuthProvidersLoadedMsg => new AdminAuthProvidersLoadedMsg([]),
                    static fn (\Throwable $e): Msg => $e instanceof AuthError
                        ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                        : new AdminAuthProvidersFailedMsg(self::LOAD_FAILED),
                ),
        );
    }

    // ---- error ---------------------------------------------------------

    private function withError(string $reason): self
    {
        $next = clone $this;
        $next->error = $reason;
        $next->loading = false;

        return $next;
    }

    private function reloading(): self
    {
        $next = clone $this;
        $next->loading = true;
        $next->error = null;

        return $next;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading auth providers…";
        }
        if ($this->error !== null) {
            return "\n\n  {$this->error}\n  Press r to retry.";
        }
        if ($this->providers === []) {
            return "\n\n  No auth providers registered.";
        }

        return "\n" . Table::render(
            [
                ['title' => 'Provider', 'width' => 0],
                ['title' => 'Enabled', 'width' => 10],
                ['title' => 'Configured', 'width' => 12],
            ],
            $this->tableRows(),
            $this->selectedIndex,
            $this->cols - 4,
            Chrome::bodyHeight($this->rows),
        );
    }

    /** @return list<array{string}> */
    private function tableRows(): array
    {
        $rows = [];
        foreach ($this->providers as $provider) {
            $name = ucfirst($provider['name']);
            $enabled = !empty($provider['enabled']) ? 'Yes' : 'No';
            $configured = !empty($provider['configured']) ? 'Yes' : 'No';

            $rows[] = [$name, $enabled, $configured];
        }

        return $rows;
    }

    // ---- clone-mutate --------------------------------------------------

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
        return 'Auth Providers';
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors -----------------------------------------------------

    /** @return list<array{name:string,enabled:bool,configured:bool}> */
    public function providers(): array
    {
        return $this->providers;
    }

    public function selectedIndex(): int
    {
        return $this->selectedIndex;
    }

    public function isLoading(): bool
    {
        return $this->loading;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}

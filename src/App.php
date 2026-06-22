<?php

declare(strict_types=1);

namespace Phlix\Console;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\NetworkError;
use Phlix\Console\Config\Config;
use Phlix\Console\Msg\BootResolvedMsg;
use Phlix\Console\Msg\LoginFailedMsg;
use Phlix\Console\Msg\LoginSucceededMsg;
use Phlix\Console\Msg\SubmitLoginMsg;
use Phlix\Console\Msg\SubmitServerMsg;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Screen\LoginScreen;
use Phlix\Console\Screen\ServerScreen;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Root model: a hand-rolled router over the auth flow.
 *
 * Boot picks the entry screen — first-run server wizard, or a loading state
 * while a stored token is validated. App owns the shared services (Config,
 * ApiClient, AuthStore), runs the async login/restore Cmds, and routes input
 * to the active screen. Because Program only calls init() once on the root,
 * the App returns each new screen's focus-init Cmd when it transitions.
 */
final class App implements Model
{
    use SubscriptionCapable;

    public function __construct(
        private readonly Config $config,
        private readonly AuthStore $auth,
        private readonly ApiClient $api,
        private readonly Route $route,
        private readonly ?Model $screen,
        private readonly ?\Closure $bootCmd = null,
        private readonly int $cols = 80,
        private readonly int $rows = 24,
    ) {
    }

    /** Pick the entry screen from persisted config. */
    public static function boot(Config $config, AuthStore $auth, ApiClient $api): self
    {
        if (!$config->hasServer()) {
            $screen = ServerScreen::create();

            return new self($config, $auth, $api, Route::ServerSetup, $screen, $screen->init());
        }

        return new self($config, $auth, $api, Route::Loading, null, self::restoreCmd($auth));
    }

    public function init(): ?\Closure
    {
        return $this->bootCmd;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $this->isInterrupt($msg)) {
            return [$this, Cmd::quit()];
        }

        if ($msg instanceof WindowSizeMsg) {
            $screen = $this->screen?->update($msg)[0] ?? null;

            return [$this->resized($msg->cols, $msg->rows, $screen), null];
        }

        if ($msg instanceof BootResolvedMsg) {
            return $msg->user !== null ? $this->goBrowse($msg->user) : $this->goLogin(null);
        }
        if ($msg instanceof SubmitServerMsg) {
            return $this->onServerSubmitted($msg->url);
        }
        if ($msg instanceof SubmitLoginMsg) {
            return $this->onLoginSubmitted($msg->username, $msg->password);
        }
        if ($msg instanceof LoginSucceededMsg) {
            return $this->goBrowse($msg->user);
        }
        if ($msg instanceof LoginFailedMsg) {
            return $this->goLogin($msg->reason);
        }

        // Anything else (keys, focus, async suggestions) → the active screen.
        if ($this->screen !== null) {
            [$screen, $cmd] = $this->screen->update($msg);

            return [$this->withScreen($screen), $cmd];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->screen !== null) {
            return $this->screen->view();
        }

        // Loading: no active screen.
        return Chrome::frame('Connecting', "\n  Connecting to your Phlix server…", '', $this->cols, $this->rows);
    }

    // ---- transitions ---------------------------------------------------

    private function onServerSubmitted(string $url): array
    {
        $url = Config::normalizeUrl($url);
        if ($url === '') {
            return $this->goServerSetup('Please enter a server URL.');
        }

        try {
            $this->config->withServerUrl($url)->save();
        } catch (\Throwable) {
            // Persisting is best-effort; proceed with the in-memory URL anyway.
        }
        $this->api->setBaseUrl($url);

        return [$this->navigate(Route::Loading, null), self::restoreCmd($this->auth)];
    }

    private function goServerSetup(?string $error): array
    {
        $screen = ServerScreen::create($error, $this->cols, $this->rows);

        return [$this->navigate(Route::ServerSetup, $screen), $screen->init()];
    }

    private function onLoginSubmitted(string $username, string $password): array
    {
        $cmd = Cmd::promise(fn () => $this->auth->login($username, $password)->then(
            static fn (AuthUser $user): Msg => new LoginSucceededMsg($user),
            fn (\Throwable $error): Msg => new LoginFailedMsg($this->friendlyError($error)),
        ));

        // The LoginScreen already shows its "signing in" state.
        return [$this, $cmd];
    }

    private function goBrowse(AuthUser $user): array
    {
        $screen = new BrowseScreen($user, $this->cols, $this->rows);

        return [$this->navigate(Route::Browse, $screen), $screen->init()];
    }

    private function goLogin(?string $error): array
    {
        $screen = LoginScreen::create($error, $this->cols, $this->rows);

        return [$this->navigate(Route::Login, $screen), $screen->init()];
    }

    // ---- helpers -------------------------------------------------------

    /** A Cmd that validates any stored token and reports the result. */
    private static function restoreCmd(AuthStore $auth): \Closure
    {
        return Cmd::promise(static fn () => $auth->restore()->then(
            static fn (?AuthUser $user): Msg => new BootResolvedMsg($user),
            // restore() is contractually non-rejecting; guard anyway so an
            // unexpected throw becomes "show login", never a crashed program.
            static fn (\Throwable $error): Msg => new BootResolvedMsg(null),
        ));
    }

    private function friendlyError(\Throwable $error): string
    {
        if ($error instanceof NetworkError) {
            return 'Could not reach the server. Check the URL and your connection.';
        }

        $message = $error->getMessage();

        return $message !== '' ? $message : 'Login failed. Please try again.';
    }

    private function isInterrupt(KeyMsg $msg): bool
    {
        return $msg->type === KeyType::Char && $msg->rune === 'c' && $msg->ctrl;
    }

    private function navigate(Route $route, ?Model $screen): self
    {
        return new self($this->config, $this->auth, $this->api, $route, $screen, null, $this->cols, $this->rows);
    }

    private function withScreen(Model $screen): self
    {
        return new self($this->config, $this->auth, $this->api, $this->route, $screen, null, $this->cols, $this->rows);
    }

    private function resized(int $cols, int $rows, ?Model $screen): self
    {
        return new self($this->config, $this->auth, $this->api, $this->route, $screen, null, $cols, $rows);
    }

    // ---- accessors (for tests) ----------------------------------------

    public function route(): Route
    {
        return $this->route;
    }

    public function screen(): ?Model
    {
        return $this->screen;
    }
}

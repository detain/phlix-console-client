<?php

declare(strict_types=1);

namespace Phlix\Console;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\NetworkError;
use Phlix\Console\Config\Config;
use Phlix\Console\Msg\BootResolvedMsg;
use Phlix\Console\Msg\LoginFailedMsg;
use Phlix\Console\Msg\LoginSucceededMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\OpenLibraryMsg;
use Phlix\Console\Msg\PlayRequestedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\SubmitLoginMsg;
use Phlix\Console\Msg\SubmitServerMsg;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Screen\Breadcrumbed;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Screen\DetailScreen;
use Phlix\Console\Screen\LibraryScreen;
use Phlix\Console\Screen\LoginScreen;
use Phlix\Console\Screen\PlayerScreen;
use Phlix\Console\Screen\ServerScreen;
use Phlix\Console\Screen\Teardownable;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Root model: a hand-rolled router over a screen STACK.
 *
 * The authenticated app is a stack of screens (Browse → Library → …) so the
 * user can drill in and back out while each screen keeps its own state — which
 * candy-core's ScreenStack cannot do (it writes back only the root's model).
 * The auth flow (server wizard / loading / login) is a single-frame stack;
 * logging in replaces the whole stack with Browse. App owns the shared services
 * and runs the async login/restore Cmds. Because Program only calls init() once
 * on the root, the App returns each pushed/replaced screen's focus-init Cmd.
 *
 * @phpstan-type Frame array{route: Route, screen: Model}
 */
final class App implements Model
{
    use SubscriptionCapable;

    /**
     * @param list<array{route: Route, screen: Model}> $stack top frame last; empty = the loading state
     */
    public function __construct(
        private readonly Config $config,
        private readonly AuthStore $auth,
        private readonly ApiClient $api,
        private readonly LibrariesStore $libraries,
        private readonly MediaStore $media,
        private readonly PosterLoader $posters,
        private readonly array $stack,
        private readonly ?\Closure $bootCmd = null,
        private readonly int $cols = 80,
        private readonly int $rows = 24,
    ) {
    }

    /** Pick the entry screen from persisted config. */
    public static function boot(
        Config $config,
        AuthStore $auth,
        ApiClient $api,
        LibrariesStore $libraries,
        MediaStore $media,
        PosterLoader $posters,
    ): self {
        if (!$config->hasServer()) {
            $screen = ServerScreen::create();

            return new self($config, $auth, $api, $libraries, $media, $posters, [['route' => Route::ServerSetup, 'screen' => $screen]], $screen->init());
        }

        return new self($config, $auth, $api, $libraries, $media, $posters, [], self::restoreCmd($auth));
    }

    public function init(): ?\Closure
    {
        return $this->bootCmd;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $this->isInterrupt($msg)) {
            // A global Ctrl-C quit must still tear down a screen holding external
            // resources (the player's ffmpeg/ffplay subprocesses) so they don't leak.
            $top = $this->topScreen();
            if ($top instanceof Teardownable) {
                $top->teardown();
            }

            return [$this, Cmd::quit()];
        }

        if ($msg instanceof WindowSizeMsg) {
            return $this->resized($msg->cols, $msg->rows);
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
        if ($msg instanceof SessionExpiredMsg) {
            return $this->goLogin($msg->reason);
        }
        if ($msg instanceof OpenLibraryMsg) {
            return $this->openLibrary($msg->libraryId, $msg->name);
        }
        if ($msg instanceof OpenDetailMsg) {
            return $this->openDetail($msg->id, $msg->name);
        }
        if ($msg instanceof PlayRequestedMsg) {
            return $this->openPlayer($msg->item);
        }
        if ($msg instanceof NavigateBackMsg) {
            return [$this->popScreen(), null];
        }

        // Anything else (keys, focus, async results) → the active (top) screen.
        $top = $this->topScreen();
        if ($top !== null) {
            [$screen, $cmd] = $top->update($msg);

            return [$this->withTopScreen($screen), $cmd];
        }

        return [$this, null];
    }

    public function view(): string
    {
        $top = $this->topScreen();
        // Hand the top screen the breadcrumb trail built from the whole stack
        // (only the App knows it) just before it renders its chrome.
        if ($top instanceof Breadcrumbed) {
            return $top->withCrumbs($this->breadcrumbTrail())->view();
        }
        if ($top !== null) {
            return $top->view();
        }

        // Loading: no active screen.
        return Chrome::frame('Connecting', "\n  Connecting to your Phlix server…", '', $this->cols, $this->rows);
    }

    /**
     * The breadcrumb labels of the stacked breadcrumbed frames, root-first
     * (Home › Movies › The Matrix › Season 1 › …).
     *
     * @return list<string>
     */
    private function breadcrumbTrail(): array
    {
        $trail = [];
        foreach ($this->stack as $frame) {
            $screen = $frame['screen'];
            if ($screen instanceof Breadcrumbed) {
                $trail[] = $screen->crumbLabel();
            }
        }

        return $trail;
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

        return [$this->withStack([]), self::restoreCmd($this->auth)];
    }

    private function goServerSetup(?string $error): array
    {
        $screen = ServerScreen::create($error, $this->cols, $this->rows);

        return [$this->replace(Route::ServerSetup, $screen), $screen->init()];
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
        $screen = new BrowseScreen(
            $user,
            $this->libraries,
            $this->media,
            $this->posters,
            cols: $this->cols,
            rows: $this->rows,
        );

        return [$this->replace(Route::Browse, $screen), $screen->init()];
    }

    private function goLogin(?string $error): array
    {
        $screen = LoginScreen::create($error, $this->cols, $this->rows);

        return [$this->replace(Route::Login, $screen), $screen->init()];
    }

    private function openLibrary(string $libraryId, string $name): array
    {
        $screen = new LibraryScreen(
            $libraryId,
            $name,
            $this->media,
            $this->posters,
            cols: $this->cols,
            rows: $this->rows,
        );

        return [$this->push(Route::Library, $screen), $screen->init()];
    }

    private function openDetail(string $id, string $name): array
    {
        $screen = new DetailScreen(
            $id,
            $name,
            $this->media,
            $this->posters,
            cols: $this->cols,
            rows: $this->rows,
        );

        return [$this->push(Route::Detail, $screen), $screen->init()];
    }

    private function openPlayer(MediaItem $item): array
    {
        $screen = new PlayerScreen(
            $item,
            $this->api->baseUrl(),
            PlayerScreen::productionFactory(),
            cols: $this->cols,
            rows: $this->rows,
        );

        return [$this->push(Route::Player, $screen), $screen->init()];
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

    // ---- stack ---------------------------------------------------------

    /** @return array{route: Route, screen: Model}|null */
    private function topFrame(): ?array
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    private function topScreen(): ?Model
    {
        return $this->topFrame()['screen'] ?? null;
    }

    /** Replace the whole stack with a single frame (auth transitions). */
    private function replace(Route $route, Model $screen): self
    {
        // The whole current stack is discarded — release any screen's external
        // resources first (e.g. a player's ffmpeg/ffplay) so a SessionExpired or
        // logout mid-playback can't leak a subprocess.
        $this->tearDownFrames($this->stack);

        return $this->withStack([['route' => $route, 'screen' => $screen]]);
    }

    /** Push a frame on top (drill-in). */
    private function push(Route $route, Model $screen): self
    {
        $stack = $this->stack;
        $stack[] = ['route' => $route, 'screen' => $screen];

        return $this->withStack($stack);
    }

    /** Pop the top frame, revealing the one beneath (never empties below 1). */
    private function popScreen(): self
    {
        if (count($this->stack) <= 1) {
            return $this;
        }

        $stack = $this->stack;
        $popped = array_pop($stack);
        // Popping permanently discards the frame (drilling back up), so release
        // its resources (idempotent — a PlayerScreen also self-tears-down on Esc).
        if ($popped !== null && $popped['screen'] instanceof Teardownable) {
            $popped['screen']->teardown();
        }

        return $this->withStack($stack);
    }

    /**
     * Tear down every {@see Teardownable} screen in a set of frames being
     * discarded, so external resources (ffmpeg/ffplay) are released, not leaked.
     *
     * @param list<array{route: Route, screen: Model}> $frames
     */
    private function tearDownFrames(array $frames): void
    {
        foreach ($frames as $frame) {
            if ($frame['screen'] instanceof Teardownable) {
                $frame['screen']->teardown();
            }
        }
    }

    private function withTopScreen(Model $screen): self
    {
        if ($this->stack === []) {
            return $this;
        }

        $stack = $this->stack;
        $top = count($stack) - 1;
        $stack[$top] = ['route' => $stack[$top]['route'], 'screen' => $screen];

        return $this->withStack($stack);
    }

    /**
     * @param list<array{route: Route, screen: Model}> $stack
     */
    private function withStack(array $stack): self
    {
        return new self(
            $this->config, $this->auth, $this->api, $this->libraries, $this->media, $this->posters,
            $stack, null, $this->cols, $this->rows,
        );
    }

    /**
     * Re-flow every frame to the new size so a popped-to screen is already sized,
     * threading back any Cmd a frame emits (e.g. a grid that grew must fetch the
     * newly-revealed cells — dropping that Cmd would leave them skeletoned until
     * the next keypress).
     *
     * @return array{App, ?\Closure}
     */
    private function resized(int $cols, int $rows): array
    {
        $msg = new WindowSizeMsg($cols, $rows);
        $stack = [];
        $cmds = [];
        foreach ($this->stack as $frame) {
            [$screen, $cmd] = $frame['screen']->update($msg);
            $stack[] = ['route' => $frame['route'], 'screen' => $screen];
            if ($cmd !== null) {
                $cmds[] = $cmd;
            }
        }

        $app = new self(
            $this->config, $this->auth, $this->api, $this->libraries, $this->media, $this->posters,
            $stack, null, $cols, $rows,
        );

        return [$app, $cmds === [] ? null : Cmd::batch(...$cmds)];
    }

    // ---- accessors (for tests) ----------------------------------------

    public function route(): Route
    {
        return $this->topFrame()['route'] ?? Route::Loading;
    }

    public function screen(): ?Model
    {
        return $this->topScreen();
    }

    public function stackDepth(): int
    {
        return count($this->stack);
    }
}

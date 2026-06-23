<?php

declare(strict_types=1);

namespace Phlix\Console;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\NetworkError;
use Phlix\Console\Config\Config;
use Phlix\Console\Msg\BootResolvedMsg;
use Phlix\Console\Msg\GoHomeMsg;
use Phlix\Console\Msg\LoginFailedMsg;
use Phlix\Console\Msg\LoginSucceededMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenAlbumMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\OpenLibraryMsg;
use Phlix\Console\Msg\OpenSearchMsg;
use Phlix\Console\Msg\PaletteLibrariesLoadedMsg;
use Phlix\Console\Msg\PlayNextMsg;
use Phlix\Console\Msg\PlayRequestedMsg;
use Phlix\Console\Msg\RequestLogoutMsg;
use Phlix\Console\Msg\RequestQuitMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Msg\SubmitLoginMsg;
use Phlix\Console\Msg\SubmitServerMsg;
use Phlix\Console\Msg\ToastTickMsg;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Screen\AlbumScreen;
use Phlix\Console\Screen\Breadcrumbed;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Screen\DetailScreen;
use Phlix\Console\Screen\LibraryScreen;
use Phlix\Console\Screen\LoginScreen;
use Phlix\Console\Screen\MusicScreen;
use Phlix\Console\Screen\CapturesSlash;
use Phlix\Console\Screen\PlayerScreen;
use Phlix\Console\Screen\SearchScreen;
use Phlix\Console\Screen\ServerScreen;
use Phlix\Console\Screen\Teardownable;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Store\MusicStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\CommandPalette;
use Phlix\Console\Ui\PaletteAction;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Toast\Position;
use SugarCraft\Toast\Toast;

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

    /** Seconds a toast stays up before it auto-dismisses. */
    private const TOAST_DURATION = 4.0;

    /** The single toast host, floating above whatever screen is on top. */
    private readonly Toast $toast;

    /**
     * @param list<array{route: Route, screen: Model}> $stack top frame last; empty = the loading state
     * @param bool $toastTicking whether a prune tick is already in flight (so a burst of toasts doesn't stack ticks)
     * @param ?CommandPalette $palette the open command palette overlay, or null when closed
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
        ?Toast $toast = null,
        private readonly bool $toastTicking = false,
        private readonly ?CommandPalette $palette = null,
    ) {
        $this->toast = $toast ?? self::defaultToast();
    }

    private static function defaultToast(): Toast
    {
        return Toast::new()->withPosition(Position::TopRight)->withDuration(self::TOAST_DURATION);
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

        // While the command palette is open it captures all keystrokes (it is a
        // modal); non-key messages (async results, ticks) still flow through.
        if ($this->palette !== null && $msg instanceof KeyMsg) {
            return $this->handlePaletteKey($this->palette, $msg);
        }
        // Ctrl-K opens the palette from any screen; `:` also opens it, unless the
        // top screen captures text (where `:` should type).
        if ($msg instanceof KeyMsg && ($this->isPaletteToggle($msg) || $this->isColonPaletteOpen($msg))) {
            return $this->openPalette();
        }
        // `/` opens global search, unless the top screen captures it (a screen
        // with its own filter, or the player where a search overlay would orphan
        // playback).
        if ($msg instanceof KeyMsg && $this->isSearchKey($msg)) {
            return $this->openSearch();
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
            return $this->openLibrary($msg->libraryId, $msg->name, $msg->type);
        }
        if ($msg instanceof OpenAlbumMsg) {
            return $this->openAlbum($msg->album);
        }
        if ($msg instanceof OpenDetailMsg) {
            return $this->openDetail($msg->id, $msg->name);
        }
        if ($msg instanceof OpenSearchMsg) {
            return $this->openSearch();
        }
        if ($msg instanceof PlayRequestedMsg) {
            return $this->openPlayer($msg->item);
        }
        if ($msg instanceof PlayNextMsg) {
            // Replace the current player frame with the next episode's (binge
            // without growing the stack): pop the ended player (tears it down)
            // then push a fresh one beneath the same detail.
            return $this->popScreen()->openPlayer($msg->item);
        }
        if ($msg instanceof NavigateBackMsg) {
            return [$this->popScreen(), null];
        }
        if ($msg instanceof GoHomeMsg) {
            return $this->goHome();
        }
        if ($msg instanceof RequestLogoutMsg) {
            return $this->requestLogout();
        }
        if ($msg instanceof RequestQuitMsg) {
            return $this->requestQuit();
        }
        if ($msg instanceof PaletteLibrariesLoadedMsg) {
            return $this->onPaletteLibraries($msg->libraries);
        }
        if ($msg instanceof ShowToastMsg) {
            return $this->showToast($msg);
        }
        if ($msg instanceof ToastTickMsg) {
            return $this->onToastTick();
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
        $view = $this->baseView();
        // The command palette dims + centers its box over the screen; toasts then
        // float over everything (palette included).
        if ($this->palette !== null) {
            $view = $this->palette->render($view);
        }

        // Toast::View is a no-op (returns the background unchanged) when nothing
        // is queued.
        return $this->toast->View($view, max(1, $this->cols), max(1, $this->rows));
    }

    private function baseView(): string
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

    // ---- toasts --------------------------------------------------------

    private function showToast(ShowToastMsg $msg): array
    {
        $toast = $this->toast->alert($msg->type, $msg->message);
        // A burst of toasts shares one prune loop: only arm a tick if none is
        // already in flight (the pending tick reschedules from the full queue).
        $cmd = $this->toastTicking ? null : $this->toastTickCmd($toast);

        return [$this->withToast($toast, true), $cmd];
    }

    private function onToastTick(): array
    {
        $toast = $this->toast->pruneExpired();
        if ($toast->secondsUntilNextExpiry() !== null) {
            return [$this->withToast($toast, true), $this->toastTickCmd($toast)];
        }

        // Nothing left to expire — stop the loop (a later toast re-arms it).
        return [$this->withToast($toast, false), null];
    }

    private function toastTickCmd(Toast $toast): \Closure
    {
        // An active queue always reports an expiry (every toast is added with the
        // host's default duration); TOAST_DURATION is a defensive fallback only.
        $delay = $toast->secondsUntilNextExpiry() ?? self::TOAST_DURATION;

        return Cmd::tick($delay, static fn (): Msg => new ToastTickMsg());
    }

    private function withToast(Toast $toast, bool $ticking): self
    {
        return new self(
            $this->config,
            $this->auth,
            $this->api,
            $this->libraries,
            $this->media,
            $this->posters,
            $this->stack,
            null,
            $this->cols,
            $this->rows,
            $toast,
            $ticking,
            $this->palette,
        );
    }

    // ---- command palette -----------------------------------------------

    private function isPaletteToggle(KeyMsg $msg): bool
    {
        return $msg->type === KeyType::Char && $msg->rune === 'k' && $msg->ctrl;
    }

    private function isColonPaletteOpen(KeyMsg $msg): bool
    {
        return $msg->type === KeyType::Char
            && $msg->rune === ':'
            && !$msg->ctrl
            && !($this->topScreen() instanceof CapturesSlash);
    }

    private function openPalette(): array
    {
        $palette = CommandPalette::open($this->staticActions(), $this->cols, $this->rows);

        // Augment with a "Go to <library>" action per library once they load.
        return [$this->withPalette($palette), $this->fetchPaletteLibraries()];
    }

    private function handlePaletteKey(CommandPalette $palette, KeyMsg $msg): array
    {
        if ($this->isPaletteToggle($msg) || $msg->type === KeyType::Escape) {
            return [$this->withPalette(null), null];
        }
        if ($msg->type === KeyType::Enter) {
            $action = $palette->selectedAction();
            $closed = $this->withPalette(null);

            return $action !== null ? [$closed, Cmd::send($action->msg)] : [$closed, null];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->withPalette($palette->up()), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->withPalette($palette->down()), null];
        }
        if ($msg->type === KeyType::Backspace) {
            return [$this->withPalette($palette->backspace()), null];
        }
        if ($msg->type === KeyType::Space) {
            return [$this->withPalette($palette->type(' ')), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune !== '') {
            return [$this->withPalette($palette->type($msg->rune)), null];
        }

        // Any other key is swallowed — the palette is modal while open.
        return [$this, null];
    }

    /** @return list<PaletteAction> */
    private function staticActions(): array
    {
        return [
            new PaletteAction('Search', new OpenSearchMsg()),
            new PaletteAction('Home', new GoHomeMsg()),
            new PaletteAction('Log out', new RequestLogoutMsg()),
            new PaletteAction('Quit', new RequestQuitMsg()),
        ];
    }

    private function isSearchKey(KeyMsg $msg): bool
    {
        return $msg->type === KeyType::Char
            && $msg->rune === '/'
            && !$msg->ctrl
            && !($this->topScreen() instanceof CapturesSlash);
    }

    private function fetchPaletteLibraries(): \Closure
    {
        return Cmd::promise(fn () => $this->libraries->all()->then(
            static fn (array $libraries): ?Msg => new PaletteLibrariesLoadedMsg($libraries),
            // Best-effort: a failure just leaves the static actions in place.
            static fn (\Throwable $e): ?Msg => null,
        ));
    }

    /**
     * @param list<Library> $libraries
     */
    private function onPaletteLibraries(array $libraries): array
    {
        if ($this->palette === null) {
            return [$this, null]; // the palette was closed before the fetch resolved
        }

        $actions = [];
        foreach ($libraries as $library) {
            if ($library instanceof Library) {
                $actions[] = new PaletteAction('Go to ' . $library->name, new OpenLibraryMsg($library->id, $library->name, $library->type));
            }
        }
        foreach ($this->staticActions() as $action) {
            $actions[] = $action;
        }

        return [$this->withPalette($this->palette->withActions($actions)), null];
    }

    private function withPalette(?CommandPalette $palette): self
    {
        return new self(
            $this->config,
            $this->auth,
            $this->api,
            $this->libraries,
            $this->media,
            $this->posters,
            $this->stack,
            null,
            $this->cols,
            $this->rows,
            $this->toast,
            $this->toastTicking,
            $palette,
        );
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

    /** Pop the stack back to its root (the browse home). */
    private function goHome(): array
    {
        $app = $this;
        while ($app->stackDepth() > 1) {
            $app = $app->popScreen();
        }

        return [$app, null];
    }

    private function requestLogout(): array
    {
        // Drop the stored token so the next launch shows login, then go there.
        $this->auth->logout();

        return $this->goLogin(null);
    }

    private function requestQuit(): array
    {
        // Mirror the Ctrl-C path: tear down a Teardownable top screen (the
        // player's ffmpeg/ffplay) before quitting so nothing leaks.
        $top = $this->topScreen();
        if ($top instanceof Teardownable) {
            $top->teardown();
        }

        return [$this, Cmd::quit()];
    }

    private function openLibrary(string $libraryId, string $name, string $type = ''): array
    {
        // Library type decides the screen: music gets the album list; everything
        // else (movie / tv / series) gets the virtualized poster grid.
        if ($type === 'music') {
            $screen = new MusicScreen(new MusicStore($this->api), cols: $this->cols, rows: $this->rows);

            return [$this->push(Route::Music, $screen), $screen->init()];
        }

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

    private function openAlbum(Album $album): array
    {
        $screen = new AlbumScreen(
            $album,
            $this->media,
            $this->api->baseUrl(),
            AlbumScreen::productionAudioFactory(),
            cols: $this->cols,
            rows: $this->rows,
        );

        return [$this->push(Route::Album, $screen), $screen->init()];
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

    private function openSearch(): array
    {
        $screen = new SearchScreen(
            $this->media,
            $this->posters,
            cols: $this->cols,
            rows: $this->rows,
        );

        return [$this->push(Route::Search, $screen), $screen->init()];
    }

    private function openPlayer(MediaItem $item): array
    {
        $screen = new PlayerScreen(
            $item,
            $this->api->baseUrl(),
            $this->api,
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
            $stack, null, $this->cols, $this->rows, $this->toast, $this->toastTicking, $this->palette,
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
            $stack, null, $cols, $rows, $this->toast, $this->toastTicking, $this->palette?->resizedTo($cols, $rows),
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

    public function toast(): Toast
    {
        return $this->toast;
    }

    public function palette(): ?CommandPalette
    {
        return $this->palette;
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Cast\CastClient;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\Api\NetworkError;
use Phlix\Console\Audio\AudiobookSession;
use Phlix\Console\Audio\MusicSession;
use Phlix\Console\Audio\NowPlayingSession;
use Phlix\Console\Config\Config;
use Phlix\Console\Msg\AudiobookTickMsg;
use Phlix\Console\Msg\AudioSkipMsg;
use Phlix\Console\Msg\BootResolvedMsg;
use Phlix\Console\Msg\GoHomeMsg;
use Phlix\Console\Msg\LoginFailedMsg;
use Phlix\Console\Msg\OpenAdminMsg;
use Phlix\Console\Msg\OpenAdminSectionMsg;
use Phlix\Console\Msg\LoginSucceededMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\NowPlayingTickMsg;
use Phlix\Console\Msg\OpenAlbumMsg;
use Phlix\Console\Msg\OpenAudiobookMsg;
use Phlix\Console\Msg\OpenBookMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\OpenLibraryMsg;
use Phlix\Console\Msg\OpenPhotoAlbumMsg;
use Phlix\Console\Msg\OpenPhotoMsg;
use Phlix\Console\Msg\OpenSearchMsg;
use Phlix\Console\Msg\OpenSettingsMsg;
use Phlix\Console\Msg\OpenStatsMsg;
use Phlix\Console\Msg\PaletteLibrariesLoadedMsg;
use Phlix\Console\Msg\PlayAudiobookMsg;
use Phlix\Console\Msg\PlayNextMsg;
use Phlix\Console\Msg\CastRequestedMsg;
use Phlix\Console\Msg\PlayRequestedMsg;
use Phlix\Console\Msg\PlayTrackMsg;
use Phlix\Console\Msg\RequestLogoutMsg;
use Phlix\Console\Msg\RequestQuitMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\SettingsSavedMsg;
use Phlix\Console\Msg\ShimmerTickMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Msg\StopAudioMsg;
use Phlix\Console\Msg\SubmitLoginMsg;
use Phlix\Console\Msg\SubmitServerMsg;
use Phlix\Console\Msg\ToastTickMsg;
use Phlix\Console\Msg\ToggleAudioMsg;
use Phlix\Console\Msg\ToggleMetricsMsg;
use Phlix\Console\Msg\TrackResolvedMsg;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Screen\AdminBackupScreen;
use Phlix\Console\Screen\AdminDashboardScreen;
use Phlix\Console\Screen\AdminDlnaScreen;
use Phlix\Console\Screen\AdminLibrariesScreen;
use Phlix\Console\Screen\AdminLiveTvScreen;
use Phlix\Console\Screen\AdminLogsScreen;
use Phlix\Console\Screen\AdminMenuScreen;
use Phlix\Console\Screen\AdminPluginsScreen;
use Phlix\Console\Screen\AdminRemoteAccessScreen;
use Phlix\Console\Screen\AdminSettingsScreen;
use Phlix\Console\Screen\AdminUsersScreen;
use Phlix\Console\Screen\AlbumScreen;
use Phlix\Console\Screen\AudiobookDetailScreen;
use Phlix\Console\Screen\AudiobooksScreen;
use Phlix\Console\Screen\BookDetailScreen;
use Phlix\Console\Screen\BooksScreen;
use Phlix\Console\Screen\Breadcrumbed;
use Phlix\Console\Screen\CastScreen;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Screen\DetailScreen;
use Phlix\Console\Screen\LibraryScreen;
use Phlix\Console\Screen\Loadable;
use Phlix\Console\Screen\LoginScreen;
use Phlix\Console\Screen\MusicScreen;
use Phlix\Console\Screen\CapturesSlash;
use Phlix\Console\Screen\PhotoAlbumScreen;
use Phlix\Console\Screen\PhotosScreen;
use Phlix\Console\Screen\PhotoViewerScreen;
use Phlix\Console\Screen\PlayerScreen;
use Phlix\Console\Screen\SearchScreen;
use Phlix\Console\Screen\ServerScreen;
use Phlix\Console\Screen\SettingsScreen;
use Phlix\Console\Screen\Shimmering;
use Phlix\Console\Screen\StatsScreen;
use Phlix\Console\Screen\Teardownable;
use Phlix\Console\Screen\Themed;
use Phlix\Console\Store\AudiobooksStore;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Store\BooksStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Store\MusicStore;
use Phlix\Console\Store\PhotosStore;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\CommandPalette;
use Phlix\Console\Ui\MetricsOverlay;
use Phlix\Console\Ui\NowPlayingBar;
use Phlix\Console\Ui\PaletteAction;
use Phlix\Console\Ui\Theme;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;
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

    /**
     * Seconds between shimmer-skeleton animation frames (the band advances one
     * phase per tick). ~0.12s ≈ 8 fps — a smooth sweep without flooding the loop.
     */
    private const SHIMMER_INTERVAL = 0.12;

    /** The single toast host, floating above whatever screen is on top. */
    private readonly Toast $toast;

    /** The active UI theme, applied to the top screen's chrome just before render. */
    private readonly Theme $theme;

    /**
     * Builds the audio player for a resolved stream URL (music OR audiobook) —
     * injected so tests use a recording fake instead of spawning ffplay/mpv.
     *
     * @var \Closure(string $url, ?int $startMs): \SugarCraft\Reel\AudioPlayer
     */
    private readonly \Closure $audioFactory;

    /**
     * @param list<array{route: Route, screen: Model}> $stack top frame last; empty = the loading state
     * @param bool $toastTicking whether a prune tick is already in flight (so a burst of toasts doesn't stack ticks)
     * @param ?CommandPalette $palette the open command palette overlay, or null when closed
     * @param ?Theme $theme the active theme; null defaults to {@see Theme::nocturne()} (the identity look)
     * @param ?NowPlayingSession $nowPlaying the App-owned playback session (music or audiobook; persists across navigation), or null when nothing plays
     * @param ?(\Closure(string, ?int): \SugarCraft\Reel\AudioPlayer) $audioFactory builds the player for a stream URL; null defaults to the production ffplay/mpv factory
     * @param bool $metricsVisible whether the diagnostic metrics / HUD overlay is shown (toggled from the palette; default off)
     * @param int $shimmerPhase the current loading-skeleton animation phase, applied to a {@see Shimmering} top screen each render (advances only while a Loadable top screen is loading)
     * @param bool $shimmerTicking whether the gated shimmer animation tick is already in flight (so any update landing on a loading screen doesn't start a SECOND chain)
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
        ?Theme $theme = null,
        private readonly ?NowPlayingSession $nowPlaying = null,
        ?\Closure $audioFactory = null,
        private readonly bool $metricsVisible = false,
        private readonly int $shimmerPhase = 0,
        private readonly bool $shimmerTicking = false,
    ) {
        $this->toast = $toast ?? self::defaultToast();
        $this->theme = $theme ?? Theme::nocturne();
        $this->audioFactory = $audioFactory ?? MusicSession::productionAudioFactory();
    }

    private static function defaultToast(): Toast
    {
        return Toast::new()->withPosition(Position::TopRight)->withDuration(self::TOAST_DURATION);
    }

    /**
     * Pick the entry screen from persisted config.
     *
     * @param ?(\Closure(string, ?int): \SugarCraft\Reel\AudioPlayer) $audioFactory the music-player factory (a recording fake in tests); null = the production ffplay/mpv factory
     */
    public static function boot(
        Config $config,
        AuthStore $auth,
        ApiClient $api,
        LibrariesStore $libraries,
        MediaStore $media,
        PosterLoader $posters,
        ?\Closure $audioFactory = null,
    ): self {
        // The persisted theme name (if any) maps to a preset; an absent / unknown
        // name falls back to Nocturne (the identity look) via Theme::byName().
        $theme = Theme::byName((string) ($config->theme ?? ''));

        if (!$config->hasServer()) {
            $screen = ServerScreen::create();

            return new self($config, $auth, $api, $libraries, $media, $posters, [['route' => Route::ServerSetup, 'screen' => $screen]], $screen->init(), theme: $theme, audioFactory: $audioFactory);
        }

        return new self($config, $auth, $api, $libraries, $media, $posters, [], self::restoreCmd($auth), theme: $theme, audioFactory: $audioFactory);
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
            // The App owns the music audio now, so leaving stops ffplay/mpv too.
            $this->nowPlaying?->teardown();

            return [$this, Cmd::quit()];
        }

        // A SINGLE chokepoint: whatever the dispatch lands on, if the resulting top
        // screen is a still-loading Loadable, arm the shimmer animation (once). This
        // is how a transition INTO a loading grid (OpenLibrary/Search/…) — or any
        // update that leaves a loading screen on top — starts the skeleton sweep.
        return $this->maybeArmShimmer($this->dispatch($msg));
    }

    /**
     * The per-Msg router. Returns `[nextApp, ?Cmd]`; {@see update()} runs the
     * result through {@see maybeArmShimmer()} so a loading top screen animates.
     *
     * @return array{App, ?\Closure}
     */
    private function dispatch(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return $this->resized($msg->cols, $msg->rows);
        }

        // While the command palette is open it captures all keystrokes (it is a
        // modal); non-key messages (async results, ticks) still flow through.
        if ($this->palette !== null && $msg instanceof KeyMsg) {
            return $this->handlePaletteKey($this->palette, $msg);
        }
        // The palette + global search are meaningless before login (every action
        // needs a session), and on the pre-login auth forms `:`/`/` are literal
        // characters of a server URL / credentials. So all three triggers are
        // GATED on an authenticated session: while logged out the keys fall
        // through to the top screen (the form) and type normally.
        if ($this->paletteAndSearchAvailable()) {
            // Ctrl-K opens the palette from any screen; `:` also opens it, unless
            // the top screen captures text (where `:` should type).
            if ($msg instanceof KeyMsg && ($this->isPaletteToggle($msg) || $this->isColonPaletteOpen($msg))) {
                return $this->openPalette();
            }
            // `/` opens global search, unless the top screen captures it (a screen
            // with its own filter, or the player where a search overlay would
            // orphan playback).
            if ($msg instanceof KeyMsg && $this->isSearchKey($msg)) {
                return $this->openSearch();
            }
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
            return $this->openLibrary($msg->libraryId, $msg->name, $msg->type, $msg->itemCount);
        }
        if ($msg instanceof OpenAlbumMsg) {
            return $this->openAlbum($msg->album);
        }
        if ($msg instanceof OpenBookMsg) {
            return $this->openBook($msg->id, $msg->title);
        }
        if ($msg instanceof OpenAudiobookMsg) {
            return $this->openAudiobook($msg->id, $msg->title);
        }
        if ($msg instanceof OpenPhotoAlbumMsg) {
            return $this->openPhotoAlbum($msg->album);
        }
        if ($msg instanceof OpenPhotoMsg) {
            return $this->openPhoto($msg->album, $msg->index);
        }
        if ($msg instanceof OpenDetailMsg) {
            return $this->openDetail($msg->id, $msg->name);
        }
        if ($msg instanceof OpenSearchMsg) {
            return $this->openSearch();
        }
        if ($msg instanceof OpenSettingsMsg) {
            return $this->openSettings();
        }
        if ($msg instanceof OpenStatsMsg) {
            return $this->openStats();
        }
        if ($msg instanceof OpenAdminMsg) {
            return $this->openAdmin();
        }
        if ($msg instanceof OpenAdminSectionMsg) {
            return $this->openAdminSection($msg->section);
        }
        if ($msg instanceof SettingsSavedMsg) {
            return $this->saveSettings($msg->themeName, $msg->slideshowInterval);
        }
        if ($msg instanceof PlayTrackMsg) {
            return $this->playTrack($msg->album, $msg->index);
        }
        if ($msg instanceof TrackResolvedMsg) {
            return $this->onTrackResolved($msg->album, $msg->index, $msg->url);
        }
        if ($msg instanceof NowPlayingTickMsg) {
            return $this->onNowPlayingTick($msg->epoch);
        }
        if ($msg instanceof ToggleAudioMsg) {
            return $this->toggleAudio();
        }
        if ($msg instanceof AudioSkipMsg) {
            return $this->skipAudio($msg->delta);
        }
        if ($msg instanceof StopAudioMsg) {
            return $this->stopAudio();
        }
        if ($msg instanceof ToggleMetricsMsg) {
            return [$this->withMetricsVisible(!$this->metricsVisible), null];
        }
        if ($msg instanceof PlayAudiobookMsg) {
            return $this->playAudiobook($msg->audiobook, $msg->chapters, $msg->startMs);
        }
        if ($msg instanceof AudiobookTickMsg) {
            return $this->onAudiobookTick($msg->epoch);
        }
        if ($msg instanceof PlayRequestedMsg) {
            return $this->openPlayer($msg->item);
        }
        if ($msg instanceof CastRequestedMsg) {
            return $this->openCast($msg->item);
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
        if ($msg instanceof ShimmerTickMsg) {
            return $this->onShimmerTick();
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
        // The diagnostic HUD sits in the top-left UNDER everything else: drawn over
        // the base but before the now-playing bar + the modals.
        if ($this->metricsVisible) {
            $view = MetricsOverlay::render($view, $this->metricsLines(), $this->cols, $this->rows, $this->theme);
        }
        // The App owns the playback audio (music OR audiobook), so a persistent
        // now-playing bar is composited onto the bottom row of every screen
        // (replacing the chrome's last line) — playback stays visible as the user
        // navigates. It is drawn AFTER the HUD so the bar always wins the bottom
        // row even on a very short terminal where the (taller) HUD reaches it. The
        // palette + toasts then layer ON TOP of both.
        if ($this->nowPlaying !== null) {
            $view = $this->compositeNowPlayingBar($view, $this->nowPlaying);
        }
        // The command palette dims + centers its box over the screen; toasts then
        // float over everything (palette included).
        if ($this->palette !== null) {
            $view = $this->palette->render($view);
        }

        // Toast::View is a no-op (returns the background unchanged) when nothing
        // is queued.
        return $this->toast->View($view, max(1, $this->cols), max(1, $this->rows));
    }

    /**
     * Replace the bottom row of $view with $nowPlaying's bar, ANSI-safe. The
     * chrome renders exactly $rows lines, so the last line is the status row; we
     * swap just that one line and leave every line above untouched (a precise,
     * non-corrupting line-replace — overlays composite over the result).
     * `explode("\n", …)` always yields at least one element, so the last-line
     * index is always valid.
     */
    private function compositeNowPlayingBar(string $view, NowPlayingSession $nowPlaying): string
    {
        $bar = NowPlayingBar::render($nowPlaying, max(1, $this->cols), $this->theme);

        $lines = explode("\n", $view);
        $lines[count($lines) - 1] = $bar;

        return implode("\n", $lines);
    }

    /**
     * The diagnostic HUD's `Label  value` lines — runtime metrics + current app
     * state, all read straight off the live App + PHP (no counters / no timing).
     * The labels are padded to a common width so the values line up.
     *
     * @return list<string>
     */
    private function metricsLines(): array
    {
        $mem = memory_get_usage(true) / 1048576;
        $peak = memory_get_peak_usage(true) / 1048576;

        $topFrame = $this->topFrame();
        $route = $topFrame !== null ? $topFrame['route']->name : '—';

        return [
            self::metric('Mem', sprintf('%.1f / %.1f MB', $mem, $peak)),
            self::metric('Term', $this->cols . '×' . $this->rows),
            self::metric('Route', $route),
            self::metric('Depth', (string) count($this->stack)),
            self::metric('Theme', $this->theme->name),
            self::metric('Audio', $this->audioMetric()),
        ];
    }

    /** The `Audio` HUD value: `idle`, or `music|audiobook: <title>` (+ paused). */
    private function audioMetric(): string
    {
        $np = $this->nowPlaying;
        if ($np === null) {
            return 'idle';
        }

        $kind = $np instanceof AudiobookSession ? 'audiobook' : 'music';
        $suffix = $np->paused() ? ' (paused)' : '';

        return $kind . ': ' . $np->title() . $suffix;
    }

    /**
     * One padded `Label  value` HUD row: the label column is a fixed 7 cells so
     * the values line up and even the widest label (5 chars) keeps a 2-space gap.
     */
    private static function metric(string $label, string $value): string
    {
        return str_pad($label, 7) . $value;
    }

    private function baseView(): string
    {
        $top = $this->topScreen();
        if ($top !== null) {
            // Apply the active theme then the breadcrumb trail (both known only to
            // the App) transiently, just before the top screen renders its chrome —
            // each is a clone-mutate that leaves the stacked screen untouched. Under
            // Nocturne withTheme() is a render no-op, so the frame is unchanged.
            $rendered = $top;
            if ($rendered instanceof Themed) {
                $rendered = $rendered->withTheme($this->theme);
            }
            if ($rendered instanceof Breadcrumbed) {
                $rendered = $rendered->withCrumbs($this->breadcrumbTrail());
            }
            // The shimmer phase reaches a loading screen the same transient way: the
            // App owns the animation clock, so it hands the current phase to a
            // Shimmering top screen just before it renders its skeleton body.
            if ($rendered instanceof Shimmering) {
                $rendered = $rendered->withShimmerPhase($this->shimmerPhase);
            }

            /** @var string $body Phlix screens always render a string body */
            $body = $rendered->view();

            return $body;
        }

        // Loading: no active screen.
        return Chrome::frame('Connecting', "\n  Connecting to your Phlix server…", '', $this->cols, $this->rows, theme: $this->theme);
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

    /** @return array{App, ?\Closure} */
    private function showToast(ShowToastMsg $msg): array
    {
        $toast = $this->toast->alert($msg->type, $msg->message);
        // A burst of toasts shares one prune loop: only arm a tick if none is
        // already in flight (the pending tick reschedules from the full queue).
        $cmd = $this->toastTicking ? null : $this->toastTickCmd($toast);

        return [$this->withToast($toast, true), $cmd];
    }

    /** @return array{App, ?\Closure} */
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
            $this->theme,
            $this->nowPlaying,
            $this->audioFactory,
            $this->metricsVisible,
            $this->shimmerPhase,
            $this->shimmerTicking,
        );
    }

    // ---- shimmer (loading-skeleton animation) --------------------------
    //
    // The loading grids show an animated shimmer skeleton driven by a phase the
    // App advances on a tick. The tick is GATED — armed only while a Loadable top
    // screen is loading, stopped the moment it isn't — and SINGLE-CHAIN: a
    // $shimmerTicking flag (mirroring $toastTicking) means a burst of updates on a
    // loading screen can never start a second heartbeat. The pattern is the toast
    // prune tick relocated to the loading-skeleton animation.

    /** Wrap-around modulus for the phase counter (keeps it a small bounded int). */
    private const SHIMMER_PHASE_MODULUS = 100_000;

    /** Whether the top screen is a {@see Loadable} that is still loading. */
    private function topIsLoading(): bool
    {
        $top = $this->topScreen();

        return $top instanceof Loadable && $top->isLoading();
    }

    /**
     * If the update is about to leave a still-loading Loadable on top and no
     * shimmer tick is in flight, flip $shimmerTicking on and BATCH a shimmer tick
     * onto the outgoing Cmd (never dropping it). Otherwise the pair passes through
     * unchanged — so a tick can't start twice (single chain) and never runs while
     * nothing is loading (gated). Called at the single {@see update()} chokepoint.
     *
     * @param array{App, ?\Closure} $result the [app, cmd] the dispatch produced
     * @return array{App, ?\Closure}
     */
    private function maybeArmShimmer(array $result): array
    {
        [$app, $cmd] = $result;
        if (!$app->topIsLoading() || $app->shimmerTicking) {
            return $result;
        }

        $armed = $app->withShimmer($app->shimmerPhase, true);
        $tick = self::shimmerTickCmd();

        return [$armed, $cmd === null ? $tick : Cmd::batch($cmd, $tick)];
    }

    /**
     * One shimmer frame: advance the phase, then re-arm WHILE a Loadable top
     * screen is still loading (keeping $shimmerTicking true) or STOP otherwise
     * (clear $shimmerTicking, no re-arm — a later transition into a loading screen
     * re-arms via {@see maybeArmShimmer()}). Mirrors {@see onToastTick()}.
     *
     * @return array{App, ?\Closure}
     */
    private function onShimmerTick(): array
    {
        $phase = ($this->shimmerPhase + 1) % self::SHIMMER_PHASE_MODULUS;

        if ($this->topIsLoading()) {
            return [$this->withShimmer($phase, true), self::shimmerTickCmd()];
        }

        return [$this->withShimmer($phase, false), null];
    }

    private static function shimmerTickCmd(): \Closure
    {
        return Cmd::tick(self::SHIMMER_INTERVAL, static fn (): Msg => new ShimmerTickMsg());
    }

    /** A copy carrying the shimmer animation $phase + whether its tick is in flight. */
    private function withShimmer(int $phase, bool $ticking): self
    {
        return new self(
            $this->config, $this->auth, $this->api, $this->libraries, $this->media, $this->posters,
            $this->stack, null, $this->cols, $this->rows, $this->toast, $this->toastTicking, $this->palette, $this->theme,
            $this->nowPlaying, $this->audioFactory, $this->metricsVisible, $phase, $ticking,
        );
    }

    // ---- command palette -----------------------------------------------

    /**
     * Whether the command palette and global search are available — i.e. an
     * authenticated session exists. They are gated on this because every palette
     * action needs a session, and on the pre-login auth screens (server URL /
     * login) the `:`/`/` keys are literal characters the user must be able to
     * type. The {@see AuthStore} user is null on the ServerSetup + Login screens
     * and set after login/restore (cleared on logout) — exactly the "logged in"
     * signal, the same one the Admin palette gate already reads.
     */
    private function paletteAndSearchAvailable(): bool
    {
        return $this->auth->currentUser() !== null;
    }

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

    /** @return array{App, ?\Closure} */
    private function openPalette(): array
    {
        $palette = CommandPalette::open($this->staticActions(), $this->cols, $this->rows);

        // Augment with a "Go to <library>" action per library once they load.
        return [$this->withPalette($palette), $this->fetchPaletteLibraries()];
    }

    /** @return array{App, ?\Closure} */
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
        $actions = [
            new PaletteAction('Search', new OpenSearchMsg()),
            new PaletteAction('Home', new GoHomeMsg()),
            new PaletteAction('Settings', new OpenSettingsMsg()),
            new PaletteAction('Stats', new OpenStatsMsg()),
            // The metrics / HUD overlay is toggled from the palette (no global key,
            // so no conflict); the label flips with the current visibility.
            new PaletteAction($this->metricsVisible ? 'Hide metrics' : 'Show metrics', new ToggleMetricsMsg()),
        ];
        // The admin area is offered ONLY to an admin user — the gate is the
        // restored/current user's is_admin flag. A non-admin (or logged-out) palette
        // never sees it.
        if ($this->auth->currentUser()?->isAdmin === true) {
            $actions[] = new PaletteAction('Admin', new OpenAdminMsg());
        }
        // Playback controls are universal (no key conflict) — surfaced in the
        // palette only while a session (music or audiobook) is playing, so they
        // reach the App-owned audio from any screen.
        if ($this->nowPlaying !== null) {
            $actions[] = new PaletteAction(
                $this->nowPlaying->paused() ? 'Resume playback' : 'Pause playback',
                new ToggleAudioMsg(),
            );
            $actions[] = new PaletteAction('Stop playback', new StopAudioMsg());
        }
        $actions[] = new PaletteAction('Log out', new RequestLogoutMsg());
        $actions[] = new PaletteAction('Quit', new RequestQuitMsg());

        return $actions;
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
            static fn (array $libraries): Msg => new PaletteLibrariesLoadedMsg($libraries),
            // Best-effort: a failure just leaves the static actions in place.
            static fn (\Throwable $e): ?Msg => null,
        ));
    }

    /**
     * @param list<Library> $libraries
     * @return array{App, ?\Closure}
     */
    private function onPaletteLibraries(array $libraries): array
    {
        if ($this->palette === null) {
            return [$this, null]; // the palette was closed before the fetch resolved
        }

        $actions = [];
        foreach ($libraries as $library) {
            $actions[] = new PaletteAction('Go to ' . $library->name, new OpenLibraryMsg($library->id, $library->name, $library->type, $library->itemCount));
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
            $this->theme,
            $this->nowPlaying,
            $this->audioFactory,
            $this->metricsVisible,
            $this->shimmerPhase,
            $this->shimmerTicking,
        );
    }

    // ---- transitions ---------------------------------------------------

    /** @return array{App, ?\Closure} */
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

    /** @return array{App, ?\Closure} */
    private function goServerSetup(?string $error): array
    {
        $screen = ServerScreen::create($error, $this->cols, $this->rows);

        return [$this->replace(Route::ServerSetup, $screen), $screen->init()];
    }

    /** @return array{App, ?\Closure} */
    private function onLoginSubmitted(string $username, string $password): array
    {
        $cmd = Cmd::promise(fn () => $this->auth->login($username, $password)->then(
            static fn (AuthUser $user): Msg => new LoginSucceededMsg($user),
            fn (\Throwable $error): Msg => new LoginFailedMsg($this->friendlyError($error)),
        ));

        // The LoginScreen already shows its "signing in" state.
        return [$this, $cmd];
    }

    /** @return array{App, ?\Closure} */
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

    /** @return array{App, ?\Closure} */
    private function goLogin(?string $error): array
    {
        $screen = LoginScreen::create($error, $this->cols, $this->rows);

        return [$this->replace(Route::Login, $screen), $screen->init()];
    }

    /**
     * Pop the stack back to its root (the browse home).
     *
     * @return array{App, ?\Closure}
     */
    private function goHome(): array
    {
        $app = $this;
        while ($app->stackDepth() > 1) {
            $app = $app->popScreen();
        }

        return [$app, null];
    }

    /** @return array{App, ?\Closure} */
    private function requestLogout(): array
    {
        // Drop the stored token so the next launch shows login, then go there.
        $this->auth->logout();

        return $this->goLogin(null);
    }

    /** @return array{App, ?\Closure} */
    private function requestQuit(): array
    {
        // Mirror the Ctrl-C path: tear down a Teardownable top screen (the
        // player's ffmpeg/ffplay) before quitting so nothing leaks.
        $top = $this->topScreen();
        if ($top instanceof Teardownable) {
            $top->teardown();
        }
        // The App owns the music audio now, so leaving stops ffplay/mpv too.
        $this->nowPlaying?->teardown();

        return [$this, Cmd::quit()];
    }

    /** @return array{App, ?\Closure} */
    private function openLibrary(string $libraryId, string $name, string $type = '', int $itemCount = 0): array
    {
        // Library type decides the screen: music gets the album list; book gets
        // the lazy-cover grid; everything else (movie / tv / series) gets the
        // virtualized poster grid.
        if ($type === 'music') {
            $screen = new MusicScreen(new MusicStore($this->api), cols: $this->cols, rows: $this->rows);

            return [$this->push(Route::Music, $screen), $screen->init()];
        }
        if ($type === 'book') {
            // A BooksStore is built locally (the App holds no books field) so no
            // with*/boot threading is needed; the library's item count seeds the
            // grid total (the /books endpoint sends none).
            $screen = new BooksScreen(
                new BooksStore($this->api),
                $this->posters,
                $this->api->baseUrl(),
                $libraryId,
                $name,
                $itemCount,
                cols: $this->cols,
                rows: $this->rows,
            );

            return [$this->push(Route::Books, $screen), $screen->init()];
        }
        if ($type === 'audiobook') {
            // An AudiobooksStore is built locally (the App holds no audiobooks
            // field, like BooksStore); the screen pages the whole library at once.
            $screen = new AudiobooksScreen(
                new AudiobooksStore($this->api),
                $libraryId,
                $name,
                cols: $this->cols,
                rows: $this->rows,
            );

            return [$this->push(Route::Audiobooks, $screen), $screen->init()];
        }
        if ($type === 'photo') {
            // A PhotosStore is built locally (the App holds no photos field, like
            // BooksStore); the store fetches every album (each with its photos and
            // signed thumbnails) in one call, so the screen needs no item count.
            $screen = new PhotosScreen(
                new PhotosStore($this->api),
                $this->posters,
                $this->api->baseUrl(),
                $libraryId,
                $name,
                cols: $this->cols,
                rows: $this->rows,
            );

            return [$this->push(Route::Photos, $screen), $screen->init()];
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

    /** @return array{App, ?\Closure} */
    private function openAlbum(Album $album): array
    {
        // The AlbumScreen is now a pure list that EMITS play/control Msgs; the
        // App owns the music audio (MusicSession), so the screen needs no store /
        // base URL / audio factory.
        $screen = new AlbumScreen($album, cols: $this->cols, rows: $this->rows);

        return [$this->push(Route::Album, $screen), $screen->init()];
    }

    /** @return array{App, ?\Closure} */
    private function openBook(string $id, string $title): array
    {
        // A fresh BooksStore (the App holds no books field) — the detail fetches
        // the signed cover/download URLs the grid's list rows lack.
        $screen = new BookDetailScreen(
            new BooksStore($this->api),
            $this->posters,
            $this->api->baseUrl(),
            $id,
            $title,
            cols: $this->cols,
            rows: $this->rows,
        );

        return [$this->push(Route::BookDetail, $screen), $screen->init()];
    }

    /** @return array{App, ?\Closure} */
    private function openAudiobook(string $id, string $title): array
    {
        // A fresh AudiobooksStore (the App holds no audiobooks field) — the
        // detail fetches the chapter list and the signed stream URL the list lacks.
        // The screen is now a pure chapter list that EMITS play/control Msgs; the
        // App owns the audiobook audio (AudiobookSession), so the screen needs no
        // base URL / audio factory and is no longer Teardownable.
        $screen = new AudiobookDetailScreen(
            new AudiobooksStore($this->api),
            $id,
            $title,
            cols: $this->cols,
            rows: $this->rows,
        );

        return [$this->push(Route::AudiobookDetail, $screen), $screen->init()];
    }

    /** @return array{App, ?\Closure} */
    private function openPhotoAlbum(PhotoAlbum $album): array
    {
        // The album carries its photos (each with a signed thumbnail), so the
        // screen builds its grid from them with no further fetch.
        $screen = new PhotoAlbumScreen(
            $album,
            $this->posters,
            $this->api->baseUrl(),
            cols: $this->cols,
            rows: $this->rows,
        );

        return [$this->push(Route::PhotoAlbum, $screen), $screen->init()];
    }

    /** @return array{App, ?\Closure} */
    private function openPhoto(PhotoAlbum $album, int $index): array
    {
        // A PhotosStore is built locally (the App holds no photos field, like
        // BookDetailScreen) — the viewer fetches each photo's EXIF off its detail;
        // the album already carries the signed `full_url`s for the images.
        $screen = new PhotoViewerScreen(
            $album,
            $index,
            new PhotosStore($this->api),
            $this->posters,
            $this->api->baseUrl(),
            cols: $this->cols,
            rows: $this->rows,
            slideInterval: (float) $this->config->slideshowInterval,
        );

        return [$this->push(Route::PhotoViewer, $screen), $screen->init()];
    }

    /** @return array{App, ?\Closure} */
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

    /** @return array{App, ?\Closure} */
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

    /** @return array{App, ?\Closure} */
    private function openSettings(): array
    {
        $screen = SettingsScreen::create(
            $this->theme->name,
            $this->config->slideshowInterval,
            $this->cols,
            $this->rows,
        );

        return [$this->push(Route::Settings, $screen), $screen->init()];
    }

    /** @return array{App, ?\Closure} */
    private function openStats(): array
    {
        // The stats panel aggregates the libraries the App already fetches, so it
        // reuses the App's LibrariesStore (the same cache the browse home + palette
        // use) — no new store instance.
        $screen = new StatsScreen($this->libraries, $this->cols, $this->rows);

        return [$this->push(Route::Stats, $screen), $screen->init()];
    }

    /**
     * Open the admin area's section index (the menu). The {@see AdminMenuScreen}
     * is the scaffolding every later admin surface hangs off; it needs no store,
     * so it is pushed with no fetch.
     *
     * @return array{App, ?\Closure}
     */
    private function openAdmin(): array
    {
        $screen = new AdminMenuScreen($this->cols, $this->rows);

        return [$this->push(Route::Admin, $screen), $screen->init()];
    }

    /**
     * Open one admin section (from the menu). Each wired section pushes its screen
     * with an {@see AdminClient} built LOCALLY from the shared {@see ApiClient} (the
     * App holds no AdminClient field — mirroring the BooksStore-built-locally
     * pattern). Any not-yet-wired section is a no-op (the menu only emits available
     * sections).
     *
     * @return array{App, ?\Closure}
     */
    private function openAdminSection(Route $section): array
    {
        if ($section === Route::AdminDashboard) {
            $screen = new AdminDashboardScreen(new AdminClient($this->api), $this->cols, $this->rows);

            return [$this->push(Route::AdminDashboard, $screen), $screen->init()];
        }
        if ($section === Route::AdminUsers) {
            $screen = new AdminUsersScreen(new AdminClient($this->api), $this->cols, $this->rows);

            return [$this->push(Route::AdminUsers, $screen), $screen->init()];
        }
        if ($section === Route::AdminPlugins) {
            $screen = new AdminPluginsScreen(new AdminClient($this->api), $this->cols, $this->rows);

            return [$this->push(Route::AdminPlugins, $screen), $screen->init()];
        }
        if ($section === Route::AdminLogs) {
            $screen = new AdminLogsScreen(new AdminClient($this->api), $this->cols, $this->rows);

            return [$this->push(Route::AdminLogs, $screen), $screen->init()];
        }
        if ($section === Route::AdminBackup) {
            $screen = new AdminBackupScreen(new AdminClient($this->api), $this->cols, $this->rows);

            return [$this->push(Route::AdminBackup, $screen), $screen->init()];
        }
        if ($section === Route::AdminSettings) {
            $screen = new AdminSettingsScreen(new AdminClient($this->api), $this->cols, $this->rows);

            return [$this->push(Route::AdminSettings, $screen), $screen->init()];
        }
        if ($section === Route::AdminLibraries) {
            $screen = new AdminLibrariesScreen(new AdminClient($this->api), $this->cols, $this->rows);

            return [$this->push(Route::AdminLibraries, $screen), $screen->init()];
        }
        if ($section === Route::AdminDlna) {
            $screen = new AdminDlnaScreen(new AdminClient($this->api), $this->cols, $this->rows);

            return [$this->push(Route::AdminDlna, $screen), $screen->init()];
        }
        if ($section === Route::AdminRemote) {
            $screen = new AdminRemoteAccessScreen(new AdminClient($this->api), $this->cols, $this->rows);

            return [$this->push(Route::AdminRemote, $screen), $screen->init()];
        }
        if ($section === Route::AdminLiveTv) {
            $screen = new AdminLiveTvScreen(new AdminClient($this->api), $this->cols, $this->rows);

            return [$this->push(Route::AdminLiveTv, $screen), $screen->init()];
        }

        return [$this, null];
    }

    /**
     * Apply a settings change: persist the new theme + slideshow interval
     * (best-effort), switch the live theme, and pop the Settings frame so the
     * user returns to the screen they opened it from. The theme applies LIVE
     * because {@see baseView()} re-applies $this->theme each render; the new
     * interval flows into future {@see openPhoto()} pushes via $this->config.
     *
     * @return array{App, ?\Closure}
     */
    private function saveSettings(string $themeName, int $slideshowInterval): array
    {
        $newTheme = Theme::byName($themeName);
        $newConfig = $this->config->withTheme($themeName)->withSlideshowInterval($slideshowInterval);

        try {
            $newConfig->save();
        } catch (\Throwable) {
            // Persisting is best-effort; proceed with the in-memory config anyway.
        }

        // Pop the Settings frame, then apply the new config + theme to that copy.
        return [$this->popScreen()->withConfig($newConfig)->withTheme($newTheme), null];
    }

    // ---- music audio (App-owned session) -------------------------------
    //
    // The music audio lives on the App (not the AlbumScreen) so playback
    // persists across navigation, shown by the NowPlayingBar. The epoch
    // discipline is relocated VERBATIM from the old AlbumScreen: every (re)start
    // of the heartbeat bumps {@see MusicSession::epoch()} so a tick from a
    // superseded chain is dropped as stale — never two heartbeats at once.

    /** A toast shown when a track can't be resolved/played. */
    private const PLAY_TRACK_FAILED = 'Could not play this track';

    /**
     * Start playing track $index of $album: resolve its signed stream URL
     * ({@see MediaStore::item}), then → {@see TrackResolvedMsg} which spawns the
     * player. An out-of-range index is a no-op. If a session is already playing,
     * its heartbeat is superseded (epoch bumped) so the outgoing track can't keep
     * ticking/auto-advancing while the new track resolves — exactly as the old
     * AlbumScreen::play did.
     *
     * @return array{App, ?\Closure}
     */
    private function playTrack(Album $album, int $index): array
    {
        $track = $album->tracks[$index] ?? null;
        if ($track === null) {
            return [$this, null];
        }

        $app = $this->nowPlaying !== null
            ? $this->withNowPlaying($this->nowPlaying->withEpoch($this->nowPlaying->epoch() + 1))
            : $this;

        return [$app, $app->fetchTrackCmd($album, $index, $track->id)];
    }

    /**
     * Resolve track $index's signed stream URL via the detail endpoint, then →
     * {@see TrackResolvedMsg}. A missing/empty URL or a non-auth error becomes an
     * error toast (current playback untouched); an auth failure surfaces as a
     * session expiry. Mirrors the old AlbumScreen::fetchStreamCmd + resolveUrl.
     */
    private function fetchTrackCmd(Album $album, int $index, string $trackId): \Closure
    {
        return Cmd::promise(fn (): PromiseInterface => $this->media->item($trackId)->then(
            function (MediaItem $item) use ($album, $index): Msg {
                $url = $item->streamUrl;
                if ($url === null || $url === '') {
                    return ShowToastMsg::error(self::PLAY_TRACK_FAILED);
                }

                return new TrackResolvedMsg($album, $index, $this->resolveUrl($url));
            },
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg('Your session expired. Please sign in again.')
                : ShowToastMsg::error(self::PLAY_TRACK_FAILED),
        ));
    }

    /**
     * The stream URL resolved — stop any current player, spawn the new one, open
     * (or replace) the App's now-playing session, and arm the 1-second heartbeat
     * under a fresh epoch. Mirrors the old AlbumScreen::onAudioStarted.
     *
     * @return array{App, ?\Closure}
     */
    private function onTrackResolved(Album $album, int $index, string $url): array
    {
        $prevEpoch = $this->nowPlaying?->epoch() ?? 0;
        $this->nowPlaying?->teardown();

        $player = ($this->audioFactory)($url, null);
        $player->start();

        $epoch = $prevEpoch + 1;
        $nowPlaying = new MusicSession($player, $album, $index, paused: false, positionSecs: 0, epoch: $epoch);

        return [$this->withNowPlaying($nowPlaying), $this->nowPlayingTickCmd($epoch)];
    }

    /**
     * One playback second elapsed: advance the estimated position and re-arm the
     * tick. At/after the track's known duration, auto-advance to the next track
     * (re-resolve + play, bumping the epoch) or stop at the last one. A tick from
     * a superseded heartbeat — or while nothing plays / paused — is dropped.
     * Mirrors the old AlbumScreen::onAudioTick EXACTLY.
     *
     * @return array{App, ?\Closure}
     */
    private function onNowPlayingTick(int $epoch): array
    {
        $np = $this->nowPlaying;
        // MUSIC-only: an AudiobookSession ticks via AudiobookTickMsg, never this
        // one (the two heartbeats never cross-fire).
        if (!$np instanceof MusicSession || $epoch !== $np->epoch() || $np->paused()) {
            return [$this, null];
        }

        $advanced = $np->withPositionSecs($np->positionSecs() + 1);

        $duration = $np->durationSecs();
        if ($duration !== null && $advanced->positionSecs() >= $duration) {
            // Track finished — advance to the next, or stop at the end.
            $nextIndex = $np->trackIndex() + 1;
            if ($nextIndex < count($np->album()->tracks)) {
                return $this->playTrack($np->album(), $nextIndex);
            }
            $np->teardown();

            return [$this->withNowPlaying(null), null];
        }

        // Continue the SAME generation (no epoch bump here).
        return [$this->withNowPlaying($advanced), $this->nowPlayingTickCmd($epoch)];
    }

    /**
     * Toggle pause/resume on the active session — works for BOTH a music track and
     * an audiobook via the {@see NowPlayingSession} interface. Bumps the epoch
     * either way: pausing invalidates the in-flight tick (no re-arm); resuming
     * starts a fresh heartbeat no leftover tick can double, re-armed with the
     * RIGHT tick Msg for the session kind (music vs audiobook never cross-fire).
     * Mirrors the old AlbumScreen/AudiobookDetailScreen togglePause.
     *
     * @return array{App, ?\Closure}
     */
    private function toggleAudio(): array
    {
        $np = $this->nowPlaying;
        if ($np === null) {
            return [$this, null];
        }

        $epoch = $np->epoch() + 1;
        if (!$np->paused()) {
            $np->player()->pause();
            $paused = $np->withPaused(true)->withEpoch($epoch);

            // An audiobook persists its position the moment you pause, so a
            // pause-then-quit resumes exactly where you stopped (music has no
            // progress endpoint, so it just holds).
            $cmd = $paused instanceof AudiobookSession ? $this->reportProgressCmd($paused) : null;

            return [$this->withNowPlaying($paused), $cmd];
        }

        $np->player()->resume();

        // Re-arm the heartbeat dedicated to this session kind.
        $tick = $np instanceof AudiobookSession
            ? $this->audiobookTickCmd($epoch)
            : $this->nowPlayingTickCmd($epoch);

        return [$this->withNowPlaying($np->withPaused(false)->withEpoch($epoch)), $tick];
    }

    /**
     * Move the session to the track $delta away (next/prev), if in range — the
     * resolve→onTrackResolved chain bumps the epoch (and playTrack supersedes the
     * current heartbeat). A no-op when nothing plays or the move runs off an end.
     *
     * @return array{App, ?\Closure}
     */
    private function skipAudio(int $delta): array
    {
        $np = $this->nowPlaying;
        // MUSIC-only: an audiobook seeks via PlayAudiobookMsg (Enter on a chapter),
        // never AudioSkip.
        if (!$np instanceof MusicSession) {
            return [$this, null];
        }
        $target = $np->trackIndex() + $delta;
        if ($target < 0 || $target >= count($np->album()->tracks)) {
            return [$this, null];
        }

        return $this->playTrack($np->album(), $target);
    }

    /**
     * Stop + clear the active session (tears the player down; bar disappears).
     *
     * @return array{App, ?\Closure}
     */
    private function stopAudio(): array
    {
        $this->nowPlaying?->teardown();

        return [$this->withNowPlaying(null), null];
    }

    private function nowPlayingTickCmd(int $epoch): \Closure
    {
        return Cmd::tick(1.0, static fn (): Msg => new NowPlayingTickMsg($epoch));
    }

    // ---- audiobook audio (App-owned session) ---------------------------
    //
    // The audiobook audio lives on the App (not the AudiobookDetailScreen) so
    // playback persists across navigation, shown by the same NowPlayingBar. An
    // audiobook is ONE signed stream; chapters are seek markers. Its heartbeat is
    // a DEDICATED AudiobookTickMsg (never the music NowPlayingTickMsg) under the
    // same epoch discipline relocated VERBATIM from AudiobookDetailScreen.

    /** A toast shown when an audiobook can't be played (missing stream URL). */
    private const PLAY_AUDIOBOOK_FAILED = 'Cannot play this audiobook';

    /**
     * Play (or seek) $audiobook at $startMs (a chapter start, or a resume
     * position): stop any existing session, spawn the player over the ONE signed
     * stream URL (already on the Msg — SYNCHRONOUS, no fetch, exactly like the old
     * AudiobookDetailScreen::playFrom), open the App's now-playing session, and arm
     * the audiobook heartbeat under a fresh epoch. A missing/empty stream URL
     * surfaces an error toast and plays nothing.
     *
     * @param list<AudiobookChapter> $chapters
     * @return array{App, ?\Closure}
     */
    private function playAudiobook(Audiobook $audiobook, array $chapters, int $startMs): array
    {
        if ($audiobook->streamUrl === null || $audiobook->streamUrl === '') {
            return [$this, Cmd::send(ShowToastMsg::error(self::PLAY_AUDIOBOOK_FAILED))];
        }

        $prevEpoch = $this->nowPlaying?->epoch() ?? 0;
        $this->nowPlaying?->teardown();

        $player = ($this->audioFactory)($this->resolveUrl($audiobook->streamUrl), $startMs);
        $player->start();

        $epoch = $prevEpoch + 1;
        $session = new AudiobookSession(
            $player,
            $audiobook,
            $chapters,
            positionMs: $startMs,
            paused: false,
            epoch: $epoch,
            ticksSinceReport: 0,
        );

        return [$this->withNowPlaying($session), $this->audiobookTickCmd($epoch)];
    }

    /**
     * One playback second elapsed during an audiobook: advance the estimated
     * position (+1000ms) and re-arm the tick. At/after the audiobook's known
     * duration the book finishes (stop + a FINAL ~100% progress report); every
     * ~10 ticks a throttled progress save fires. A tick from a superseded
     * heartbeat — or while nothing plays / not an AudiobookSession / paused — is
     * dropped. Mirrors the old AudiobookDetailScreen::onAudiobookTick EXACTLY.
     *
     * @return array{App, ?\Closure}
     */
    private function onAudiobookTick(int $epoch): array
    {
        $session = $this->nowPlaying;
        // AUDIOBOOK-only: a MusicSession ticks via NowPlayingTickMsg, never this
        // one (the two heartbeats never cross-fire).
        if (!$session instanceof AudiobookSession || $epoch !== $session->epoch() || $session->paused()) {
            return [$this, null];
        }

        $next = $session->ticked();

        if ($next->endReached()) {
            // The book finished — stop and fire a final report at ~100%.
            $report = $this->reportProgressCmd($next);
            $next->teardown();

            return [$this->withNowPlaying(null), $report];
        }

        $cmds = [$this->audiobookTickCmd($epoch)];
        if ($next->shouldReport()) {
            // Throttled ~10s save: keep the heartbeat going AND persist progress.
            $next = $next->withReported();
            $cmds[] = $this->reportProgressCmd($next);
        }

        return [$this->withNowPlaying($next), Cmd::batch(...$cmds)];
    }

    /**
     * Persist an audiobook session's position fire-and-forget: a failed save
     * NEVER disrupts playback (both arms swallow to null). The App holds no
     * AudiobooksStore field, so one is built locally from $this->api (like the
     * open-handlers do). The report reads the SESSION's current chapter / completed
     * chapters / percent.
     */
    private function reportProgressCmd(AudiobookSession $session): \Closure
    {
        $store = new AudiobooksStore($this->api);

        return Cmd::promise(static fn (): PromiseInterface => $store->saveProgress(
            $session->audiobookId(),
            $session->positionMs(),
            $session->currentChapterIndex(),
            $session->completedChapterIndices(),
            $session->percentComplete(),
        )->then(static fn (): ?Msg => null, static fn (): ?Msg => null));
    }

    private function audiobookTickCmd(int $epoch): \Closure
    {
        return Cmd::tick(1.0, static fn (): Msg => new AudiobookTickMsg($epoch));
    }

    /** Resolve a (possibly relative) URL against the server base; absolute/empty pass through. */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->api->baseUrl(), '/') . '/' . ltrim($url, '/');
    }

    /** @return array{App, ?\Closure} */
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

    /**
     * Open the "Cast to…" screen for an item with a signed stream. The
     * {@see CastClient} is built locally from the shared ApiClient (the App holds
     * no CastClient field).
     *
     * @return array{App, ?\Closure}
     */
    private function openCast(MediaItem $item): array
    {
        $screen = new CastScreen(
            new CastClient($this->api),
            $item,
            $this->api->baseUrl(),
            cols: $this->cols,
            rows: $this->rows,
        );

        return [$this->push(Route::Cast, $screen), $screen->init()];
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
        // resources first (e.g. a player's ffmpeg/ffplay) AND the App-owned audio
        // session (the now-playing music/audiobook), so a SessionExpired or logout
        // mid-playback can't leak an ffplay/mpv subprocess or strand the
        // now-playing bar on the login screen. (Audio is App-owned since it became
        // persistent across navigation, so tearing down the frames is not enough.)
        $this->tearDownFrames($this->stack);
        $this->nowPlaying?->teardown();

        return $this->withNowPlaying(null)->withStack([['route' => $route, 'screen' => $screen]]);
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
        if ($popped['screen'] instanceof Teardownable) {
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
            $stack, null, $this->cols, $this->rows, $this->toast, $this->toastTicking, $this->palette, $this->theme,
            $this->nowPlaying, $this->audioFactory, $this->metricsVisible, $this->shimmerPhase, $this->shimmerTicking,
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
            $stack, null, $cols, $rows, $this->toast, $this->toastTicking, $this->palette?->resizedTo($cols, $rows), $this->theme,
            $this->nowPlaying, $this->audioFactory, $this->metricsVisible, $this->shimmerPhase, $this->shimmerTicking,
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

    /** The active UI theme (Nocturne by default). */
    public function theme(): Theme
    {
        return $this->theme;
    }

    /** The current client config (server URL, theme name, slideshow interval). */
    public function config(): Config
    {
        return $this->config;
    }

    /** The App's active playback session (music or audiobook), or null when nothing is playing. */
    public function nowPlaying(): ?NowPlayingSession
    {
        return $this->nowPlaying;
    }

    /** The current loading-skeleton animation phase (advances while a Loadable top screen loads). */
    public function shimmerPhase(): int
    {
        return $this->shimmerPhase;
    }

    /** Whether a gated shimmer animation tick is currently in flight (the single-chain guard). */
    public function isShimmerTicking(): bool
    {
        return $this->shimmerTicking;
    }

    /**
     * A copy switched to $theme (T2 uses this to apply a live theme change). The
     * theme is applied transiently to the top screen on the next render, so the
     * stacked screens need no rebuild.
     */
    public function withTheme(Theme $theme): self
    {
        return new self(
            $this->config, $this->auth, $this->api, $this->libraries, $this->media, $this->posters,
            $this->stack, null, $this->cols, $this->rows, $this->toast, $this->toastTicking, $this->palette, $theme,
            $this->nowPlaying, $this->audioFactory, $this->metricsVisible, $this->shimmerPhase, $this->shimmerTicking,
        );
    }

    /**
     * A copy carrying $config (Settings save persists the new theme name +
     * slideshow interval here so future {@see openPhoto()} pushes pick it up).
     * Every other field is preserved.
     */
    public function withConfig(Config $config): self
    {
        return new self(
            $config, $this->auth, $this->api, $this->libraries, $this->media, $this->posters,
            $this->stack, null, $this->cols, $this->rows, $this->toast, $this->toastTicking, $this->palette, $this->theme,
            $this->nowPlaying, $this->audioFactory, $this->metricsVisible, $this->shimmerPhase, $this->shimmerTicking,
        );
    }

    /**
     * A copy carrying the App's active playback session $nowPlaying (music or
     * audiobook, or null to clear it). Every other field is preserved. Internal:
     * audio transitions route through here so the now-playing bar follows the
     * session.
     */
    private function withNowPlaying(?NowPlayingSession $nowPlaying): self
    {
        return new self(
            $this->config, $this->auth, $this->api, $this->libraries, $this->media, $this->posters,
            $this->stack, null, $this->cols, $this->rows, $this->toast, $this->toastTicking, $this->palette, $this->theme,
            $nowPlaying, $this->audioFactory, $this->metricsVisible, $this->shimmerPhase, $this->shimmerTicking,
        );
    }

    /**
     * A copy whose music-player factory is $audioFactory (a recording fake in
     * tests). Every other field is preserved. The test seam for the App's
     * audio session (so AppTest can capture stream URLs without spawning ffplay).
     *
     * @param \Closure(string, ?int): \SugarCraft\Reel\AudioPlayer $audioFactory
     */
    public function withAudioFactory(\Closure $audioFactory): self
    {
        return new self(
            $this->config, $this->auth, $this->api, $this->libraries, $this->media, $this->posters,
            $this->stack, null, $this->cols, $this->rows, $this->toast, $this->toastTicking, $this->palette, $this->theme,
            $this->nowPlaying, $audioFactory, $this->metricsVisible, $this->shimmerPhase, $this->shimmerTicking,
        );
    }

    /**
     * A copy with the metrics / HUD overlay toggled (or set explicitly). Every
     * other field is preserved — the flag persists across navigation because it
     * is threaded through every {@see withStack}/push/pop copy.
     */
    private function withMetricsVisible(bool $metricsVisible): self
    {
        return new self(
            $this->config, $this->auth, $this->api, $this->libraries, $this->media, $this->posters,
            $this->stack, null, $this->cols, $this->rows, $this->toast, $this->toastTicking, $this->palette, $this->theme,
            $this->nowPlaying, $this->audioFactory, $metricsVisible, $this->shimmerPhase, $this->shimmerTicking,
        );
    }
}

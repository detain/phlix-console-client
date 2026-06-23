<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\App;
use Phlix\Console\Config\Config;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Config\TokenStore;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\AudioStartedMsg;
use Phlix\Console\Msg\BootResolvedMsg;
use Phlix\Console\Msg\GoHomeMsg;
use Phlix\Console\Msg\LoginFailedMsg;
use Phlix\Console\Msg\LoginSucceededMsg;
use Phlix\Console\Msg\MediaRangeLoadedMsg;
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
use Phlix\Console\Route;
use Phlix\Console\Screen\AlbumScreen;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Screen\CapturesSlash;
use Phlix\Console\Screen\DetailScreen;
use Phlix\Console\Screen\LibraryScreen;
use Phlix\Console\Screen\LoginScreen;
use Phlix\Console\Screen\MusicScreen;
use Phlix\Console\Screen\PlayerScreen;
use Phlix\Console\Screen\SearchScreen;
use Phlix\Console\Screen\ServerScreen;
use Phlix\Console\Screen\Teardownable;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaRange;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Tests\Reel\FakeAudioPlayer;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Toast\Position;
use SugarCraft\Toast\Toast;
use SugarCraft\Toast\ToastType;

final class AppTest extends TestCase
{
    private string $dir;
    private string|false $prevXdg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-app-' . bin2hex(random_bytes(6));
        // Redirect config + token persistence into a temp dir.
        $this->prevXdg = getenv('XDG_CONFIG_HOME');
        putenv('XDG_CONFIG_HOME=' . $this->dir);
    }

    protected function tearDown(): void
    {
        $this->prevXdg === false ? putenv('XDG_CONFIG_HOME') : putenv('XDG_CONFIG_HOME=' . $this->prevXdg);
        foreach (glob($this->dir . '/phlix/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/phlix');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{App, AuthStore, ApiClient} */
    private function makeApp(?string $server, FakeTransport $transport): array
    {
        $config = new Config($server);
        $api = new ApiClient($server ?? '', $transport);
        $auth = new AuthStore($api, TokenStore::default());
        $libraries = new LibrariesStore($api);
        $media = new MediaStore($api);
        $posters = new PosterLoader(Mosaic::halfBlock());

        return [App::boot($config, $auth, $api, $libraries, $media, $posters), $auth, $api];
    }

    /**
     * Build an App directly over a hand-made screen stack (so a Teardownable spy
     * can be placed where a real player would be).
     *
     * @param list<array{route: Route, screen: Model}> $stack
     */
    private function appWithStack(array $stack, ?Toast $toast = null): App
    {
        $api = new ApiClient('https://srv', new FakeTransport());

        return new App(
            new Config('https://srv'),
            new AuthStore($api, TokenStore::default()),
            $api,
            new LibrariesStore($api),
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            $stack,
            toast: $toast,
        );
    }

    /** A toast pre-loaded with one alert at the given absolute expiry. */
    private function toastExpiringAt(string $message, float $expiresAt, ToastType $type = ToastType::Info): Toast
    {
        return Toast::new()->withPosition(Position::TopRight)->alert($type, $message, $expiresAt);
    }

    public function testFirstRunShowsServerWizard(): void
    {
        [$app] = $this->makeApp(null, new FakeTransport());

        self::assertSame(Route::ServerSetup, $app->route());
        self::assertInstanceOf(ServerScreen::class, $app->screen());
        self::assertInstanceOf(\Closure::class, $app->init(), 'boot returns the wizard focus Cmd');
    }

    public function testBootWithServerButNoTokenLoadsThenShowsLogin(): void
    {
        [$app] = $this->makeApp('https://srv', new FakeTransport());
        self::assertSame(Route::Loading, $app->route());

        // init() restore Cmd resolves null (no stored token).
        $resolved = $this->runCmd($app->init());
        self::assertInstanceOf(BootResolvedMsg::class, $resolved);
        self::assertNull($resolved->user);

        [$next] = $app->update($resolved);
        self::assertSame(Route::Login, $next->route());
        self::assertInstanceOf(LoginScreen::class, $next->screen());
    }

    public function testBootWithValidTokenGoesToBrowse(): void
    {
        TokenStore::default()->save(new TokenBundle('stored', 'refresh', 'Bearer', null));
        [$app] = $this->makeApp('https://srv', (new FakeTransport())->json(200, ['user' => ['id' => 'u1', 'username' => 'joe']]));

        $resolved = $this->runCmd($app->init());
        self::assertInstanceOf(BootResolvedMsg::class, $resolved);
        self::assertSame('joe', $resolved->user?->username);

        [$next] = $app->update($resolved);
        self::assertSame(Route::Browse, $next->route());
        self::assertInstanceOf(BrowseScreen::class, $next->screen());
    }

    public function testServerSubmittedPersistsConfigAndPointsClient(): void
    {
        [$app, , $api] = $this->makeApp(null, new FakeTransport());

        [$next, $cmd] = $app->update(new SubmitServerMsg('https://chosen.tld'));

        self::assertSame(Route::Loading, $next->route());
        self::assertSame('https://chosen.tld', $api->baseUrl());
        self::assertSame('https://chosen.tld', Config::load()->serverUrl, 'config persisted to disk');
        // The follow-up Cmd is the restore (no token → null).
        self::assertInstanceOf(BootResolvedMsg::class, $this->runCmd($cmd));
    }

    public function testEmptyServerSubmissionReturnsToWizardWithError(): void
    {
        [$app] = $this->makeApp(null, new FakeTransport());

        [$next, $cmd] = $app->update(new SubmitServerMsg('   '));

        self::assertSame(Route::ServerSetup, $next->route());
        $screen = $next->screen();
        self::assertInstanceOf(ServerScreen::class, $screen);
        self::assertNotNull($screen->error);
        self::assertInstanceOf(\Closure::class, $cmd, 'wizard is re-focused');
    }

    public function testLoginSubmittedRunsLoginAndSucceeds(): void
    {
        [$app] = $this->makeApp('https://srv', (new FakeTransport())->json(200, [
            'access_token' => 'a', 'refresh_token' => 'r', 'token_type' => 'Bearer', 'expires_in' => 3600,
            'user' => ['id' => 'u1', 'username' => 'joe'],
        ]));

        [, $cmd] = $app->update(new SubmitLoginMsg('joe', 'pw'));
        $result = $this->runCmd($cmd);

        self::assertInstanceOf(LoginSucceededMsg::class, $result);
        self::assertSame('joe', $result->user->username);
    }

    public function testLoginSubmittedSurfacesInvalidCredentials(): void
    {
        [$app] = $this->makeApp('https://srv', (new FakeTransport())->json(401, ['error' => 'Invalid username or password']));

        [, $cmd] = $app->update(new SubmitLoginMsg('joe', 'bad'));
        $result = $this->runCmd($cmd);

        self::assertInstanceOf(LoginFailedMsg::class, $result);
        self::assertSame('Invalid username or password', $result->reason);
    }

    public function testLoginSubmittedSurfacesNetworkErrorFriendly(): void
    {
        [$app] = $this->makeApp('https://srv', (new FakeTransport())->fail(new \RuntimeException('refused')));

        [, $cmd] = $app->update(new SubmitLoginMsg('joe', 'pw'));
        $result = $this->runCmd($cmd);

        self::assertInstanceOf(LoginFailedMsg::class, $result);
        self::assertStringContainsString('Could not reach the server', $result->reason);
    }

    public function testLoginSucceededRoutesToBrowse(): void
    {
        [$app] = $this->makeApp('https://srv', new FakeTransport());
        $user = AuthUser::fromArray(['id' => 'u1', 'username' => 'joe']);

        [$next, $cmd] = $app->update(new LoginSucceededMsg($user));

        self::assertSame(Route::Browse, $next->route());
        self::assertInstanceOf(BrowseScreen::class, $next->screen());
        self::assertInstanceOf(\Closure::class, $cmd, 'browse loads its data on enter');
    }

    public function testLoginFailedReturnsToLoginWithError(): void
    {
        [$app] = $this->makeApp('https://srv', new FakeTransport());

        [$next] = $app->update(new LoginFailedMsg('Account is pending approval'));

        self::assertSame(Route::Login, $next->route());
        $screen = $next->screen();
        self::assertInstanceOf(LoginScreen::class, $screen);
        self::assertSame('Account is pending approval', $screen->error);
    }

    public function testSessionExpiredReturnsToLogin(): void
    {
        [$app] = $this->makeApp('https://srv', new FakeTransport());

        [$next] = $app->update(new SessionExpiredMsg('Your session expired. Please sign in again.'));

        self::assertSame(Route::Login, $next->route());
        $screen = $next->screen();
        self::assertInstanceOf(LoginScreen::class, $screen);
        self::assertSame('Your session expired. Please sign in again.', $screen->error);
    }

    private function browsing(): App
    {
        [$app] = $this->makeApp('https://srv', new FakeTransport());
        [$browse] = $app->update(new LoginSucceededMsg(AuthUser::fromArray(['id' => 'u1', 'username' => 'joe'])));

        return $browse;
    }

    public function testOpenLibraryPushesLibraryScreen(): void
    {
        $browse = $this->browsing();
        self::assertSame(1, $browse->stackDepth());

        [$lib, $cmd] = $browse->update(new OpenLibraryMsg('lib-a', 'Movies'));

        self::assertSame(Route::Library, $lib->route());
        self::assertInstanceOf(LibraryScreen::class, $lib->screen());
        self::assertSame(2, $lib->stackDepth(), 'library is pushed onto Browse');
        self::assertInstanceOf(\Closure::class, $cmd, 'the library loads its first window on push');
    }

    public function testOpenAMusicLibraryPushesTheMusicScreen(): void
    {
        $browse = $this->browsing();

        [$music, $cmd] = $browse->update(new OpenLibraryMsg('lib-music', 'Tunes', 'music'));

        self::assertSame(Route::Music, $music->route());
        self::assertInstanceOf(MusicScreen::class, $music->screen());
        self::assertSame(2, $music->stackDepth(), 'music is pushed onto Browse');
        self::assertInstanceOf(\Closure::class, $cmd, 'the music screen fetches its albums on push');
    }

    public function testANonMusicLibraryStillPushesTheLibraryScreen(): void
    {
        $browse = $this->browsing();

        [$lib] = $browse->update(new OpenLibraryMsg('lib-a', 'Movies', 'movie'));

        self::assertSame(Route::Library, $lib->route());
        self::assertInstanceOf(LibraryScreen::class, $lib->screen());
    }

    public function testOpenAlbumPushesTheAlbumScreen(): void
    {
        $browse = $this->browsing();
        $album = Album::fromArray([
            'name' => 'Abbey Road',
            'artist' => 'The Beatles',
            'year' => 1969,
            'track_count' => 1,
            'tracks' => [['id' => 't1', 'metadata' => ['title' => 'Come Together']]],
        ]);

        [$albumScreen, $cmd] = $browse->update(new OpenAlbumMsg($album));

        self::assertSame(Route::Album, $albumScreen->route());
        self::assertInstanceOf(AlbumScreen::class, $albumScreen->screen());
        self::assertSame(2, $albumScreen->stackDepth(), 'the album is pushed on top, not replaced');
        self::assertNull($cmd, 'the album carries its tracks, so there is nothing to fetch');

        // The pushed AlbumScreen is now audio-capable (Teardownable).
        self::assertInstanceOf(Teardownable::class, $albumScreen->screen());
    }

    public function testPlayingAnAlbumTrackThenNavigatingBackTearsDownTheAudio(): void
    {
        // A real audio-capable AlbumScreen on the stack, wired to a recording
        // audio factory so no ffplay is spawned. Playing a track then popping the
        // frame (NavigateBack) must stop the audio.
        $player = null;
        $album = Album::fromArray([
            'name' => 'Abbey Road',
            'tracks' => [['id' => 't1', 'metadata' => ['title' => 'Come Together', 'duration_secs' => 100]]],
        ]);
        $transport = (new FakeTransport())->json(200, ['item' => ['id' => 't1', 'name' => 'Come Together', 'type' => 'music', 'stream_url' => 'https://srv/s/t1']]);
        $media = new MediaStore(new ApiClient('https://srv', $transport));
        $factory = function (string $url) use (&$player): FakeAudioPlayer {
            return $player = new FakeAudioPlayer($url);
        };
        $screen = new AlbumScreen($album, $media, 'https://srv', $factory, cols: 80, rows: 24);
        $app = $this->appWithStack([['route' => Route::Album, 'screen' => $screen]]);

        // Enter → the App routes the key to the AlbumScreen, which fetches the URL.
        [$loading, $cmd] = $app->update(new KeyMsg(KeyType::Enter));
        $started = $this->runCmd($cmd);
        self::assertInstanceOf(AudioStartedMsg::class, $started);
        [$playing] = $loading->update($started);
        self::assertNotNull($player);
        self::assertSame(1, $player->startCalls, 'the track started playing');

        // NavigateBack would pop the only frame (no-op below depth 1) — but the
        // App still routes the pop through teardown when a frame is discarded.
        // Push a root beneath so the album can actually pop.
        $deeper = $this->appWithStack([
            ['route' => Route::Browse, 'screen' => new SpyTeardownScreen()],
            ['route' => Route::Album, 'screen' => $playing->screen()],
        ]);
        [$popped] = $deeper->update(new NavigateBackMsg());

        self::assertSame(1, $popped->stackDepth(), 'the album frame was popped');
        self::assertSame(1, $player->stopCalls, 'popping the album stops the audio (no leaked ffplay)');
    }

    public function testOpenDetailPushesDetailScreen(): void
    {
        $browse = $this->browsing();

        [$detail, $cmd] = $browse->update(new OpenDetailMsg('m1', 'The Matrix'));

        self::assertSame(Route::Detail, $detail->route());
        self::assertInstanceOf(DetailScreen::class, $detail->screen());
        self::assertSame(2, $detail->stackDepth(), 'detail is pushed onto Browse');
        self::assertInstanceOf(\Closure::class, $cmd, 'the detail fetches its item on push');
    }

    public function testPlayRequestPushesThePlayerScreen(): void
    {
        $browse = $this->browsing();
        $item = MediaItem::fromArray([
            'id' => 'm1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'stream_url' => 'https://srv/media/m1/stream?sig=x',
        ]);

        [$player, $cmd] = $browse->update(new PlayRequestedMsg($item));

        self::assertSame(Route::Player, $player->route());
        self::assertInstanceOf(PlayerScreen::class, $player->screen());
        self::assertSame(2, $player->stackDepth(), 'player is pushed onto Browse');
        // The build Cmd is returned but intentionally NOT invoked here — running it
        // would spawn real ffmpeg. The player's own tests drive it with a fake factory.
        self::assertInstanceOf(\Closure::class, $cmd);
    }

    public function testCtrlCQuitsWithThePlayerOnTop(): void
    {
        $item = MediaItem::fromArray([
            'id' => 'm1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'stream_url' => 'https://srv/media/m1/stream?sig=x',
        ]);
        [$player] = $this->browsing()->update(new PlayRequestedMsg($item));

        // The App tears down a Teardownable top screen before quitting; on a
        // not-yet-ready player that teardown is a safe no-op, and it still quits.
        [, $cmd] = $player->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        self::assertInstanceOf(QuitMsg::class, $cmd?->__invoke());
    }

    public function testNavigateBackTearsDownAPoppedPlayer(): void
    {
        $player = new SpyTeardownScreen();
        $app = $this->appWithStack([
            ['route' => Route::Browse, 'screen' => new SpyTeardownScreen()],
            ['route' => Route::Player, 'screen' => $player],
        ]);

        $app->update(new NavigateBackMsg());

        self::assertSame(1, $player->teardownCalls, 'popping the player releases its subprocesses');
    }

    public function testSessionExpiredMidPlaybackTearsDownThePlayer(): void
    {
        // A stack-replacing transition (session expiry / logout) must not leak a
        // live player's ffmpeg — every discarded Teardownable frame is torn down.
        $player = new SpyTeardownScreen();
        $app = $this->appWithStack([
            ['route' => Route::Browse, 'screen' => new SpyTeardownScreen()],
            ['route' => Route::Player, 'screen' => $player],
        ]);

        [$next] = $app->update(new SessionExpiredMsg('expired'));

        self::assertSame(1, $player->teardownCalls, 'replacing the stack tears the player down');
        self::assertSame(Route::Login, $next->route());
    }

    public function testPlayNextReplacesTheTopPlayerFrame(): void
    {
        // [Detail, Player] → PlayNext → [Detail, Player(next)] — replaced, not grown.
        $oldPlayer = new SpyTeardownScreen();
        $app = $this->appWithStack([
            ['route' => Route::Detail, 'screen' => new SpyTeardownScreen()],
            ['route' => Route::Player, 'screen' => $oldPlayer],
        ]);
        $next = MediaItem::fromArray(['id' => 'ep2', 'name' => 'Ep 2', 'type' => 'episode', 'stream_url' => 'https://srv/s?sig=x']);

        [$app2, $cmd] = $app->update(new PlayNextMsg($next));

        self::assertSame(2, $app2->stackDepth(), 'frame count unchanged');
        self::assertSame(Route::Player, $app2->route());
        self::assertInstanceOf(PlayerScreen::class, $app2->screen());
        self::assertSame(1, $oldPlayer->teardownCalls, 'the finished player is torn down');
        self::assertInstanceOf(\Closure::class, $cmd, 'the next player builds on push');
    }

    public function testDetailCanBePushedOntoALibraryAndPoppedBack(): void
    {
        // Browse → Library → Detail, then back out one frame at a time.
        [$lib] = $this->browsing()->update(new OpenLibraryMsg('lib-a', 'Movies'));
        [$detail] = $lib->update(new OpenDetailMsg('m1', 'The Matrix'));
        self::assertSame(3, $detail->stackDepth());
        self::assertSame(Route::Detail, $detail->route());

        [$back] = $detail->update(new NavigateBackMsg());
        self::assertSame(Route::Library, $back->route(), 'popping detail reveals the library beneath');
        self::assertInstanceOf(LibraryScreen::class, $back->screen());
    }

    public function testBreadcrumbTrailIsRenderedInTheHeader(): void
    {
        // Browse → Movies → (a still-loading) detail; the header shows the path.
        [$lib] = $this->browsing()->update(new OpenLibraryMsg('lib-a', 'Movies'));
        [$detail] = $lib->update(new OpenDetailMsg('m1', 'The Matrix'));

        $view = $detail->view();
        self::assertStringContainsString('Home', $view);
        self::assertStringContainsString('Movies', $view);
        self::assertStringContainsString('The Matrix', $view);
        self::assertStringContainsString('›', $view, 'the trail is joined with breadcrumb separators');
    }

    public function testLibraryHeaderShowsTheTrailToIt(): void
    {
        [$lib] = $this->browsing()->update(new OpenLibraryMsg('lib-a', 'Movies'));

        $view = $lib->view();
        self::assertStringContainsString('Home', $view);
        self::assertStringContainsString('Movies', $view);
        self::assertStringContainsString('›', $view);
    }

    public function testBrowseHomeShowsAHomeCrumbButNoSeparator(): void
    {
        $view = $this->browsing()->view();

        self::assertStringContainsString('Home', $view);
        self::assertStringNotContainsString('›', $view, 'a single-frame stack has nothing to separate');
    }

    public function testAuthScreensHaveNoBreadcrumb(): void
    {
        // The loading state (no screen) and the login screen carry no trail.
        [$app] = $this->makeApp('https://srv', new FakeTransport());

        self::assertStringNotContainsString('›', $app->view());
    }

    public function testNestedDetailsStackForSeriesSeasonEpisode(): void
    {
        // Browse → series → season → episode, each a pushed DetailScreen.
        [$series] = $this->browsing()->update(new OpenDetailMsg('series-1', 'My Show'));
        [$season] = $series->update(new OpenDetailMsg('season-1', 'Season 1'));
        [$episode] = $season->update(new OpenDetailMsg('ep-1', 'S01E01'));

        self::assertSame(4, $episode->stackDepth());
        self::assertSame(Route::Detail, $episode->route());

        // Backing out reveals each parent in turn.
        [$backToSeason] = $episode->update(new NavigateBackMsg());
        self::assertSame(3, $backToSeason->stackDepth());
        self::assertInstanceOf(DetailScreen::class, $backToSeason->screen());
    }

    public function testNavigateBackPopsToBrowse(): void
    {
        [$lib] = $this->browsing()->update(new OpenLibraryMsg('lib-a', 'Movies'));
        self::assertSame(2, $lib->stackDepth());

        [$back] = $lib->update(new NavigateBackMsg());

        self::assertSame(1, $back->stackDepth());
        self::assertSame(Route::Browse, $back->route());
        self::assertInstanceOf(BrowseScreen::class, $back->screen());
    }

    public function testNavigateBackAtTheHomeScreenIsANoOp(): void
    {
        [$back] = $this->browsing()->update(new NavigateBackMsg());

        self::assertSame(1, $back->stackDepth(), 'cannot pop below the home screen');
        self::assertSame(Route::Browse, $back->route());
    }

    public function testWindowSizeReflowsEveryStackedFrame(): void
    {
        [$lib] = $this->browsing()->update(new OpenLibraryMsg('lib-a', 'Movies'));
        $topBefore = $lib->screen();
        self::assertInstanceOf(LibraryScreen::class, $topBefore);

        [$resized] = $lib->update(new WindowSizeMsg(50, 24));

        self::assertSame(2, $resized->stackDepth(), 'the stack survives a resize');
        $topAfter = $resized->screen();
        self::assertInstanceOf(LibraryScreen::class, $topAfter);
        self::assertLessThan($topBefore->grid()->columns(), $topAfter->grid()->columns(), 'the top frame re-flowed narrower');

        // The Browse frame beneath was also re-flowed and is intact on pop.
        [$back] = $resized->update(new NavigateBackMsg());
        self::assertInstanceOf(BrowseScreen::class, $back->screen());
    }

    public function testWidenResizeThreadsTheTopScreenFetchCmd(): void
    {
        [$lib] = $this->browsing()->update(new OpenLibraryMsg('lib-a', 'Movies'));
        // Simulate the library's first window arriving (total known), so a grown
        // viewport has cells to fetch.
        [$loaded] = $lib->update(new MediaRangeLoadedMsg(new MediaRange([], 200), 0));

        // Growing the viewport exposes cells the library must fetch; the App must
        // thread that Cmd back rather than swallow it.
        [, $cmd] = $loaded->update(new WindowSizeMsg(200, 60));

        self::assertInstanceOf(\Closure::class, $cmd, 'the grid resize-fetch Cmd is threaded through the stack');
    }

    public function testLoadingViewRendersConnecting(): void
    {
        [$app] = $this->makeApp('https://srv', new FakeTransport());

        self::assertSame(Route::Loading, $app->route());
        self::assertNull($app->screen());
        self::assertStringContainsString('Connecting', $app->view());
    }

    public function testCtrlCQuits(): void
    {
        [$app] = $this->makeApp(null, new FakeTransport());

        [, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        self::assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testPlainQIsNotAGlobalQuitOnAForm(): void
    {
        // 'q' must remain typeable in the form, not quit the app.
        [$app] = $this->makeApp(null, new FakeTransport());

        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertNotInstanceOf(QuitMsg::class, $cmd?->__invoke());
        self::assertInstanceOf(ServerScreen::class, $next->screen(), 'key was delegated to the wizard');
    }

    public function testWindowSizeIsForwardedToScreen(): void
    {
        [$app] = $this->makeApp(null, new FakeTransport());

        [$next] = $app->update(new WindowSizeMsg(133, 44));

        $screen = $next->screen();
        self::assertInstanceOf(ServerScreen::class, $screen);
        self::assertSame(133, $screen->cols);
    }

    /** Invoke a Cmd, awaiting the inner promise when it's async. */
    // ---- toasts --------------------------------------------------------

    public function testShowToastAddsAnAlertAndArmsTheTick(): void
    {
        $app = $this->appWithStack([]);
        self::assertFalse($app->toast()->hasActiveAlert());

        [$next, $cmd] = $app->update(ShowToastMsg::error('Something broke'));

        self::assertTrue($next->toast()->hasActiveAlert(), 'the alert is queued');
        self::assertInstanceOf(\Closure::class, $cmd, 'a prune tick is armed');
    }

    public function testASecondToastWhileTickingDoesNotStackAnotherTick(): void
    {
        [$first] = $this->appWithStack([])->update(ShowToastMsg::error('one'));

        [$second, $cmd] = $first->update(ShowToastMsg::info('two'));

        self::assertNull($cmd, 'the in-flight tick covers the new alert');
        self::assertTrue($second->toast()->hasActiveAlert());
    }

    public function testToastTickPrunesAnExpiredAlertAndStops(): void
    {
        $app = $this->appWithStack([], $this->toastExpiringAt('old', microtime(true) - 10.0));

        [$next, $cmd] = $app->update(new ToastTickMsg());

        self::assertFalse($next->toast()->hasActiveAlert(), 'the expired alert is gone');
        self::assertNull($cmd, 'with nothing left to expire the loop stops');
    }

    public function testToastTickReschedulesWhileAFreshAlertRemains(): void
    {
        $toast = $this->toastExpiringAt('old', microtime(true) - 10.0)
            ->alert(ToastType::Info, 'fresh', microtime(true) + 60.0);
        $app = $this->appWithStack([], $toast);

        [$next, $cmd] = $app->update(new ToastTickMsg());

        self::assertTrue($next->toast()->hasActiveAlert(), 'the fresh alert stays');
        self::assertInstanceOf(\Closure::class, $cmd, 'the tick reschedules for the remaining alert');
    }

    public function testViewCompositesAnActiveToastOverTheScreen(): void
    {
        $stack = [['route' => Route::Login, 'screen' => LoginScreen::create(null, 80, 24)]];
        $app = $this->appWithStack($stack, $this->toastExpiringAt('UPNEXT_TOAST', microtime(true) + 60.0));

        self::assertStringContainsString('UPNEXT_TOAST', $app->view(), 'the toast floats over the login screen');
    }

    public function testViewWithoutToastsIsLeftUnchanged(): void
    {
        $stack = [['route' => Route::Login, 'screen' => LoginScreen::create(null, 80, 24)]];

        $plain = $this->appWithStack($stack)->view();
        $bare = LoginScreen::create(null, 80, 24)->view();

        self::assertSame($bare, $plain, 'an empty toast host is a pure pass-through');
    }

    public function testToastSurvivesAStackReplace(): void
    {
        $app = $this->appWithStack(
            [['route' => Route::Login, 'screen' => LoginScreen::create(null, 80, 24)]],
            $this->toastExpiringAt('keep me', microtime(true) + 60.0),
        );

        [$next] = $app->update(new SessionExpiredMsg('bounce'));

        self::assertSame(Route::Login, $next->route());
        self::assertTrue($next->toast()->hasActiveAlert(), 'the toast queue survives goLogin/replace');
    }

    public function testToastSurvivesAResize(): void
    {
        $app = $this->appWithStack([], $this->toastExpiringAt('keep me', microtime(true) + 60.0));

        [$resized] = $app->update(new WindowSizeMsg(120, 40));

        self::assertTrue($resized->toast()->hasActiveAlert(), 'the toast queue survives a resize');
    }

    // ---- command palette -----------------------------------------------

    private function ctrlK(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, 'k', ctrl: true);
    }

    /** An App over a single browse-like frame (a teardown spy stands in). */
    private function paletteApp(): App
    {
        return $this->appWithStack([['route' => Route::Browse, 'screen' => new SpyTeardownScreen()]]);
    }

    public function testCtrlKOpensThePaletteWithStaticActionsAndFetchesLibraries(): void
    {
        $app = $this->paletteApp();
        self::assertNull($app->palette());

        [$next, $cmd] = $app->update($this->ctrlK());

        self::assertNotNull($next->palette());
        self::assertInstanceOf(\Closure::class, $cmd, 'opening fires the libraries fetch');
        $labels = array_map(static fn ($a): string => $a->label, $next->palette()->actions());
        self::assertSame(['Search', 'Home', 'Log out', 'Quit'], $labels);
    }

    public function testCtrlKTogglesThePaletteClosed(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());
        [$closed] = $open->update($this->ctrlK());

        self::assertNull($closed->palette());
    }

    public function testColonOpensThePaletteOnANonCapturingScreen(): void
    {
        $app = $this->appWithStack([['route' => Route::Browse, 'screen' => new SpyTeardownScreen()]]);

        [$next] = $app->update(new KeyMsg(KeyType::Char, ':'));

        self::assertNotNull($next->palette());
    }

    public function testColonIsDelegatedWhenTheTopScreenCapturesSlash(): void
    {
        $spy = new SlashCapturingScreen();
        $app = $this->appWithStack([['route' => Route::Library, 'screen' => $spy]]);

        [$next] = $app->update(new KeyMsg(KeyType::Char, ':'));

        self::assertNull($next->palette(), 'a text-capturing screen keeps the : key');
        self::assertSame(1, $spy->keyCalls);
    }

    public function testEscapeClosesThePalette(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());
        [$closed] = $open->update(new KeyMsg(KeyType::Escape));

        self::assertNull($closed->palette());
    }

    public function testPaletteTypingRanksTheMatchingAction(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());

        [$typed] = $open->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertSame('q', $typed->palette()->filterText());
        self::assertSame('Quit', $typed->palette()->visibleLabels()[0]);
    }

    public function testPaletteUpDownMoveTheSelection(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());

        [$down] = $open->update(new KeyMsg(KeyType::Down));
        self::assertSame('Home', $down->palette()->selectedAction()?->label);

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame('Search', $up->palette()->selectedAction()?->label);
    }

    public function testPaletteBackspaceAndSpaceEditTheQuery(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());

        [$typed] = $open->update(new KeyMsg(KeyType::Char, 'q'));
        [$back] = $typed->update(new KeyMsg(KeyType::Backspace));
        self::assertSame('', $back->palette()->filterText());

        [$space] = $open->update(new KeyMsg(KeyType::Space));
        self::assertSame(' ', $space->palette()->filterText());
    }

    public function testPaletteSwallowsUnhandledKeys(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());

        [$next, $cmd] = $open->update(new KeyMsg(KeyType::Tab));

        self::assertNotNull($next->palette(), 'the palette stays open and modal');
        self::assertNull($cmd);
        self::assertSame('', $next->palette()->filterText());
    }

    public function testPaletteEnterDispatchesTheSelectedActionAndCloses(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());
        [$typed] = $open->update(new KeyMsg(KeyType::Char, 'q')); // ranks "Quit"

        [$next, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertNull($next->palette(), 'selecting closes the palette');
        self::assertInstanceOf(RequestQuitMsg::class, $this->runCmd($cmd));
    }

    public function testPaletteEnterWithNoMatchClosesWithoutDispatch(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());
        // none of Home / Log out / Quit contains a 'z'.
        [$typed] = $open->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertNull($typed->palette()->selectedAction());

        [$next, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertNull($next->palette());
        self::assertNull($cmd);
    }

    public function testPaletteLibrariesAugmentTheRegistryAndOpenALibrary(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());

        $libs = [
            Library::fromArray(['id' => 'lib1', 'name' => 'Movies', 'type' => 'movie']),
            Library::fromArray(['id' => 'lib2', 'name' => 'Music', 'type' => 'music']),
        ];
        [$augmented] = $open->update(new PaletteLibrariesLoadedMsg($libs));

        $labels = array_map(static fn ($a): string => $a->label, $augmented->palette()->actions());
        self::assertContains('Go to Movies', $labels);
        self::assertContains('Go to Music', $labels);

        // Rank "Go to Movies" and select it → OpenLibraryMsg for that library.
        $typed = $augmented;
        foreach (['m', 'o', 'v', 'i', 'e'] as $rune) {
            [$typed] = $typed->update(new KeyMsg(KeyType::Char, $rune));
        }
        [, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('lib1', $msg->libraryId);
        self::assertSame('movie', $msg->type, 'the palette action carries the library type');
    }

    public function testPaletteGoToAMusicLibraryCarriesTheMusicType(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());
        $libs = [Library::fromArray(['id' => 'lib2', 'name' => 'Music', 'type' => 'music'])];
        [$augmented] = $open->update(new PaletteLibrariesLoadedMsg($libs));

        // Rank "Go to Music" and open it → OpenLibraryMsg with type 'music'.
        $typed = $augmented;
        foreach (['m', 'u', 's', 'i', 'c'] as $rune) {
            [$typed] = $typed->update(new KeyMsg(KeyType::Char, $rune));
        }
        [, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('lib2', $msg->libraryId);
        self::assertSame('music', $msg->type);
    }

    public function testPaletteLibrariesIgnoredWhenPaletteClosed(): void
    {
        $app = $this->paletteApp();

        [$next] = $app->update(new PaletteLibrariesLoadedMsg([Library::fromArray(['id' => 'x', 'name' => 'X', 'type' => 'movie'])]));

        self::assertNull($next->palette(), 'a late libraries result is dropped when the palette is closed');
    }

    public function testViewCompositesTheOpenPalette(): void
    {
        $stack = [['route' => Route::Login, 'screen' => LoginScreen::create(null, 80, 24)]];
        [$open] = $this->appWithStack($stack)->update($this->ctrlK());

        self::assertStringContainsString('Quit', $open->view(), 'the palette box floats over the screen');
    }

    public function testViewHighlightsThePaletteMatchAfterTyping(): void
    {
        // A non-empty background (LoginScreen) so the palette actually composites.
        $stack = [['route' => Route::Login, 'screen' => LoginScreen::create(null, 80, 24)]];
        [$open] = $this->appWithStack($stack)->update($this->ctrlK());
        [$typed] = $open->update(new KeyMsg(KeyType::Char, 'q')); // ranks + highlights 'Quit'

        // The bold-highlighted matched rune ('Q' of 'Quit') survives compositing
        // into the App view (Hermit.View + sugar-veil composite are ANSI-aware).
        self::assertStringContainsString("\e[1mQ", $typed->view(), 'the matched rune is highlighted in the composited view');
    }

    public function testGoHomePopsTheStackToItsRoot(): void
    {
        $app = $this->appWithStack([
            ['route' => Route::Browse, 'screen' => new SpyTeardownScreen()],
            ['route' => Route::Library, 'screen' => new SpyTeardownScreen()],
            ['route' => Route::Detail, 'screen' => new SpyTeardownScreen()],
        ]);
        self::assertSame(3, $app->stackDepth());

        [$home] = $app->update(new GoHomeMsg());

        self::assertSame(1, $home->stackDepth());
        self::assertSame(Route::Browse, $home->route());
    }

    public function testRequestQuitTearsDownTheTopScreenAndQuits(): void
    {
        $player = new SpyTeardownScreen();
        $app = $this->appWithStack([['route' => Route::Player, 'screen' => $player]]);

        [, $cmd] = $app->update(new RequestQuitMsg());

        self::assertInstanceOf(QuitMsg::class, $cmd?->__invoke());
        self::assertSame(1, $player->teardownCalls);
    }

    public function testRequestLogoutReturnsToLogin(): void
    {
        $app = $this->appWithStack([['route' => Route::Browse, 'screen' => new SpyTeardownScreen()]]);

        [$next] = $app->update(new RequestLogoutMsg());

        self::assertSame(Route::Login, $next->route());
    }

    // ---- global search -------------------------------------------------

    public function testSlashOpensGlobalSearchOnANonCapturingScreen(): void
    {
        $app = $this->appWithStack([['route' => Route::Browse, 'screen' => new SpyTeardownScreen()]]);

        [$next] = $app->update(new KeyMsg(KeyType::Char, '/'));

        self::assertSame(Route::Search, $next->route());
        self::assertInstanceOf(SearchScreen::class, $next->screen());
    }

    public function testSlashIsDelegatedWhenTheTopScreenCapturesIt(): void
    {
        $spy = new SlashCapturingScreen();
        $app = $this->appWithStack([['route' => Route::Library, 'screen' => $spy]]);

        [$next] = $app->update(new KeyMsg(KeyType::Char, '/'));

        self::assertSame(Route::Library, $next->route(), 'a CapturesSlash screen keeps the / key');
        self::assertSame(1, $spy->keyCalls, 'the key reached the screen');
    }

    public function testOpenSearchMsgPushesTheSearchScreen(): void
    {
        $app = $this->appWithStack([['route' => Route::Browse, 'screen' => new SpyTeardownScreen()]]);

        [$next] = $app->update(new OpenSearchMsg());

        self::assertSame(Route::Search, $next->route());
        self::assertSame(2, $next->stackDepth(), 'search is pushed on top, not replaced');
    }

    public function testPaletteSearchActionDispatchesOpenSearch(): void
    {
        // "Search" is the first (default-selected) palette action.
        [$open] = $this->paletteApp()->update($this->ctrlK());

        [, $cmd] = $open->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(OpenSearchMsg::class, $this->runCmd($cmd));
    }

    private function runCmd(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }

        $result = $cmd();
        if ($result instanceof AsyncCmd) {
            return $this->await($result->promise);
        }

        return $result instanceof Msg ? $result : null;
    }

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($v) use (&$state): void {
                $state['value'] = $v;
                $state['done'] = true;
                Loop::stop();
            },
            function ($e) use (&$state): void {
                $state['error'] = $e;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}

/**
 * A trivial {@see Teardownable} screen that records teardown() calls — stands in
 * for a PlayerScreen so the App's discard-teardown wiring is observable without
 * spawning ffmpeg.
 */
final class SpyTeardownScreen implements Model, Teardownable
{
    public int $teardownCalls = 0;

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return '';
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    public function teardown(): void
    {
        $this->teardownCalls++;
    }
}

/**
 * A {@see CapturesSlash} screen that records how many keys it received — used to
 * prove the App delegates `/` to a capturing screen instead of opening search.
 */
final class SlashCapturingScreen implements Model, CapturesSlash
{
    public int $keyCalls = 0;

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            $this->keyCalls++;
        }

        return [$this, null];
    }

    public function view(): string
    {
        return '';
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

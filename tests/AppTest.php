<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\App;
use Phlix\Console\Config\Config;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Config\TokenStore;
use Phlix\Console\Audio\AudiobookSession;
use Phlix\Console\Audio\MusicSession;
use Phlix\Console\Msg\AudiobookTickMsg;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\AudioSkipMsg;
use Phlix\Console\Msg\BootResolvedMsg;
use Phlix\Console\Msg\GoHomeMsg;
use Phlix\Console\Msg\LoginFailedMsg;
use Phlix\Console\Msg\LoginSucceededMsg;
use Phlix\Console\Msg\MediaRangeLoadedMsg;
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
use Phlix\Console\Msg\PlayRequestedMsg;
use Phlix\Console\Msg\PlayTrackMsg;
use Phlix\Console\Msg\RequestLogoutMsg;
use Phlix\Console\Msg\RequestQuitMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\SettingsSavedMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Msg\StopAudioMsg;
use Phlix\Console\Msg\SubmitLoginMsg;
use Phlix\Console\Msg\SubmitServerMsg;
use Phlix\Console\Msg\ToastTickMsg;
use Phlix\Console\Msg\ToggleAudioMsg;
use Phlix\Console\Msg\ToggleMetricsMsg;
use Phlix\Console\Msg\TrackResolvedMsg;
use Phlix\Console\Route;
use Phlix\Console\Screen\AlbumScreen;
use Phlix\Console\Screen\AudiobookDetailScreen;
use Phlix\Console\Screen\AudiobooksScreen;
use Phlix\Console\Screen\BookDetailScreen;
use Phlix\Console\Screen\BooksScreen;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Screen\CapturesSlash;
use Phlix\Console\Screen\DetailScreen;
use Phlix\Console\Screen\LibraryScreen;
use Phlix\Console\Screen\LoginScreen;
use Phlix\Console\Screen\MusicScreen;
use Phlix\Console\Screen\PhotoAlbumScreen;
use Phlix\Console\Screen\PhotosScreen;
use Phlix\Console\Screen\PhotoViewerScreen;
use Phlix\Console\Screen\PlayerScreen;
use Phlix\Console\Screen\SearchScreen;
use Phlix\Console\Screen\ServerScreen;
use Phlix\Console\Screen\SettingsScreen;
use Phlix\Console\Screen\StatsScreen;
use Phlix\Console\Screen\Teardownable;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaRange;
use Phlix\Console\Store\AudiobooksStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Tests\Reel\FakeAudioPlayer;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
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

    public function testOpenABookLibraryPushesTheBooksScreenWithItemCountAsTheGridTotal(): void
    {
        $browse = $this->browsing();

        [$books, $cmd] = $browse->update(new OpenLibraryMsg('lib-books', 'Reads', 'book', 42));

        self::assertSame(Route::Books, $books->route());
        $screen = $books->screen();
        self::assertInstanceOf(BooksScreen::class, $screen);
        self::assertSame(2, $books->stackDepth(), 'books is pushed onto Browse');
        self::assertSame(42, $screen->grid()->total(), 'the library item count seeds the grid total');
        self::assertInstanceOf(\Closure::class, $cmd, 'the books screen fetches its first window on push');
    }

    public function testOpenBookMsgPushesTheBookDetailScreen(): void
    {
        $browse = $this->browsing();

        [$detail, $cmd] = $browse->update(new OpenBookMsg('b1', 'Dune'));

        self::assertSame(Route::BookDetail, $detail->route());
        self::assertInstanceOf(BookDetailScreen::class, $detail->screen());
        self::assertSame(2, $detail->stackDepth(), 'the book detail is pushed on top, not replaced');
        self::assertInstanceOf(\Closure::class, $cmd, 'the book detail fetches its book on push');
    }

    public function testOpenLibraryMsgCarriesTheItemCountToTheBooksScreen(): void
    {
        // The end-to-end thread: a book-typed OpenLibraryMsg with a non-zero item
        // count surfaces as the BooksScreen grid total.
        [$books] = $this->browsing()->update(new OpenLibraryMsg('lib-books', 'Reads', 'book', 7));

        $screen = $books->screen();
        self::assertInstanceOf(BooksScreen::class, $screen);
        self::assertSame(7, $screen->grid()->total());
    }

    public function testOpenAnAudiobookLibraryPushesTheAudiobooksScreen(): void
    {
        $browse = $this->browsing();

        [$audiobooks, $cmd] = $browse->update(new OpenLibraryMsg('lib-ab', 'Listens', 'audiobook'));

        self::assertSame(Route::Audiobooks, $audiobooks->route());
        self::assertInstanceOf(AudiobooksScreen::class, $audiobooks->screen());
        self::assertSame(2, $audiobooks->stackDepth(), 'audiobooks is pushed onto Browse');
        self::assertInstanceOf(\Closure::class, $cmd, 'the audiobooks screen fetches its list on push');
    }

    public function testANonAudiobookLibraryStillRoutesAsBefore(): void
    {
        $browse = $this->browsing();

        [$lib] = $browse->update(new OpenLibraryMsg('lib-a', 'Movies', 'movie'));

        self::assertSame(Route::Library, $lib->route());
        self::assertInstanceOf(LibraryScreen::class, $lib->screen());
    }

    public function testOpenAudiobookMsgPushesTheAudiobookDetailScreen(): void
    {
        $browse = $this->browsing();

        [$detail, $cmd] = $browse->update(new OpenAudiobookMsg('ab1', 'Dune'));

        self::assertSame(Route::AudiobookDetail, $detail->route());
        self::assertInstanceOf(AudiobookDetailScreen::class, $detail->screen());
        self::assertSame(2, $detail->stackDepth(), 'the audiobook detail is pushed on top, not replaced');
        self::assertInstanceOf(\Closure::class, $cmd, 'the audiobook detail fetches its detail + chapters + progress on push');

        // The App owns the audiobook audio now, so the detail screen is a pure
        // chapter list — it is NOT Teardownable (leaving it does not stop playback).
        self::assertNotInstanceOf(Teardownable::class, $detail->screen());
    }

    public function testOpenAudiobookScreenIsNotTeardownableAndDoesNotStopAudioOnLeave(): void
    {
        // The AudiobookDetailScreen no longer owns audio — popping it must NOT tear
        // anything down (the App owns the AudiobookSession). Proven by the persist
        // test below; here we just confirm the pushed screen is not Teardownable.
        [$detail] = $this->browsing()->update(new OpenAudiobookMsg('ab1', 'Dune'));

        self::assertInstanceOf(AudiobookDetailScreen::class, $detail->screen());
        self::assertNotInstanceOf(Teardownable::class, $detail->screen());
    }

    public function testOpenAPhotoLibraryPushesThePhotosScreen(): void
    {
        $browse = $this->browsing();

        [$photos, $cmd] = $browse->update(new OpenLibraryMsg('lib-photos', 'Snaps', 'photo'));

        self::assertSame(Route::Photos, $photos->route());
        self::assertInstanceOf(PhotosScreen::class, $photos->screen());
        self::assertSame(2, $photos->stackDepth(), 'photos is pushed onto Browse');
        self::assertInstanceOf(\Closure::class, $cmd, 'the photos screen fetches its albums on push');
    }

    public function testANonPhotoLibraryStillRoutesAsBefore(): void
    {
        $browse = $this->browsing();

        [$lib] = $browse->update(new OpenLibraryMsg('lib-a', 'Movies', 'movie'));

        self::assertSame(Route::Library, $lib->route());
        self::assertInstanceOf(LibraryScreen::class, $lib->screen());
    }

    public function testOpenPhotoAlbumMsgPushesThePhotoAlbumScreen(): void
    {
        $browse = $this->browsing();
        $album = PhotoAlbum::fromArray([
            'id' => 'a0',
            'date' => '2026-06-23',
            'photo_count' => 1,
            'photos' => [['id' => 'p0', 'name' => 'p0.jpg', 'thumbnail_url' => 'https://srv/t.png']],
        ]);

        [$albumScreen, $cmd] = $browse->update(new OpenPhotoAlbumMsg($album));

        self::assertSame(Route::PhotoAlbum, $albumScreen->route());
        $screen = $albumScreen->screen();
        self::assertInstanceOf(PhotoAlbumScreen::class, $screen);
        self::assertSame(2, $albumScreen->stackDepth(), 'the album is pushed on top, not replaced');
        self::assertSame(1, $screen->grid()->total(), 'the album photos seed the grid');
        // The album carries its photos (each with a signed thumbnail), so init
        // loads the visible thumbnails directly.
        self::assertInstanceOf(\Closure::class, $cmd, 'the album loads its visible thumbnails on push');
    }

    public function testOpenPhotoMsgPushesThePhotoViewerScreen(): void
    {
        $browse = $this->browsing();
        $album = PhotoAlbum::fromArray([
            'id' => 'a0',
            'date' => '2026-06-23',
            'photo_count' => 2,
            'photos' => [
                ['id' => 'p0', 'name' => 'p0.jpg', 'thumbnail_url' => 'https://srv/t0.png', 'full_url' => 'https://srv/f0.png'],
                ['id' => 'p1', 'name' => 'p1.jpg', 'thumbnail_url' => 'https://srv/t1.png', 'full_url' => 'https://srv/f1.png'],
            ],
        ]);

        [$viewer, $cmd] = $browse->update(new OpenPhotoMsg($album, 1));

        self::assertSame(Route::PhotoViewer, $viewer->route());
        $screen = $viewer->screen();
        self::assertInstanceOf(PhotoViewerScreen::class, $screen);
        self::assertSame(2, $viewer->stackDepth(), 'the viewer is pushed on top, not replaced');
        self::assertSame(1, $screen->index(), 'the viewer opens at the requested index');
        // The photo carries a signed full_url + EXIF detail to fetch, so init
        // returns a load Cmd.
        self::assertInstanceOf(\Closure::class, $cmd, 'the viewer loads the photo image + EXIF on push');
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

        // The AlbumScreen is a pure list now (the App owns the music audio), so it
        // is NOT Teardownable — leaving it does not stop playback.
        self::assertNotInstanceOf(Teardownable::class, $albumScreen->screen());
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
        self::assertSame(['Search', 'Home', 'Settings', 'Stats', 'Show metrics', 'Log out', 'Quit'], $labels);
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

    public function testPaletteGoToAnAudiobookLibraryCarriesTheAudiobookType(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());
        $libs = [Library::fromArray(['id' => 'lib-ab', 'name' => 'Listens', 'type' => 'audiobook'])];
        [$augmented] = $open->update(new PaletteLibrariesLoadedMsg($libs));

        $labels = array_map(static fn ($a): string => $a->label, $augmented->palette()->actions());
        self::assertContains('Go to Listens', $labels);

        // Rank "Go to Listens" and open it → OpenLibraryMsg with type 'audiobook'.
        $typed = $augmented;
        foreach (['l', 'i', 's', 't', 'e', 'n', 's'] as $rune) {
            [$typed] = $typed->update(new KeyMsg(KeyType::Char, $rune));
        }
        [, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('lib-ab', $msg->libraryId);
        self::assertSame('audiobook', $msg->type, 'the palette action carries the audiobook type');
    }

    public function testPaletteGoToAPhotoLibraryCarriesThePhotoType(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());
        $libs = [Library::fromArray(['id' => 'lib-ph', 'name' => 'Snaps', 'type' => 'photo'])];
        [$augmented] = $open->update(new PaletteLibrariesLoadedMsg($libs));

        $labels = array_map(static fn ($a): string => $a->label, $augmented->palette()->actions());
        self::assertContains('Go to Snaps', $labels);

        // Rank "Go to Snaps" and open it → OpenLibraryMsg with type 'photo'.
        $typed = $augmented;
        foreach (['s', 'n', 'a', 'p', 's'] as $rune) {
            [$typed] = $typed->update(new KeyMsg(KeyType::Char, $rune));
        }
        [, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('lib-ph', $msg->libraryId);
        self::assertSame('photo', $msg->type, 'the palette action carries the photo type');
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

        if ($state['done']) {
            // The promise settled synchronously (a store wraps the sync
            // FakeTransport in a Deferred). React may still have enqueued the
            // Deferred's handler on the loop's futureTick queue — flush it with a
            // single immediate tick so no residual work leaks into a later test's
            // Loop::run(); a futureTick stop returns at once (no blocking wait).
            Loop::futureTick(static fn () => Loop::stop());
            Loop::run();
        } else {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }

    // ---- theme -----------------------------------------------------------

    public function testDefaultAppIsNocturne(): void
    {
        [$app] = $this->makeApp('https://srv', new FakeTransport());

        // A Config with no theme → the App boots Nocturne, and its loading-state
        // view carries no SGR (byte-identical to the pre-theme look).
        self::assertSame('Nocturne', $app->theme()->name);
        self::assertStringNotContainsString("\e[", $app->view(), 'the default app render has zero SGR');
    }

    public function testAppBootsThemeFromConfig(): void
    {
        $config = new Config('https://srv', 'midnight');
        $api = new ApiClient('https://srv', new FakeTransport());
        $auth = new AuthStore($api, TokenStore::default());
        $app = App::boot($config, $auth, $api, new LibrariesStore($api), new MediaStore($api), new PosterLoader(Mosaic::halfBlock()));

        self::assertSame('Midnight', $app->theme()->name, 'the persisted (case-insensitive) theme name is resolved at boot');
    }

    public function testUnknownConfigThemeFallsBackToNocturne(): void
    {
        $config = new Config('https://srv', 'no-such-theme');
        $api = new ApiClient('https://srv', new FakeTransport());
        $auth = new AuthStore($api, TokenStore::default());
        $app = App::boot($config, $auth, $api, new LibrariesStore($api), new MediaStore($api), new PosterLoader(Mosaic::halfBlock()));

        self::assertSame('Nocturne', $app->theme()->name);
    }

    public function testAppAppliesItsThemeToTheTopScreenInBaseView(): void
    {
        // A Daylight-themed App renders its (Themed) Browse top screen with the
        // accent-coloured brand — proof baseView() applies the theme transiently.
        $themed = $this->browsing()->withTheme(Theme::daylight());

        self::assertSame('Daylight', $themed->theme()->name);
        self::assertMatchesRegularExpression('/\e\[[0-9;]*m Phlix \e\[0m/', $themed->view(), 'the brand is accent-wrapped');
    }

    public function testWithThemeKeepsTheStackAndIsImmutable(): void
    {
        $browse = $this->browsing();
        $themed = $browse->withTheme(Theme::midnight());

        self::assertNotSame($browse, $themed);
        self::assertSame('Nocturne', $browse->theme()->name, 'the original app is unchanged');
        self::assertSame('Midnight', $themed->theme()->name);
        self::assertSame($browse->stackDepth(), $themed->stackDepth(), 'the screen stack is preserved');
    }

    // ---- settings --------------------------------------------------------

    public function testTheStaticPaletteActionsIncludeSettings(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());

        $labels = array_map(static fn ($a): string => $a->label, $open->palette()->actions());
        self::assertContains('Settings', $labels, 'the command palette offers a Settings action');
    }

    public function testOpenSettingsPushesTheSettingsScreenSeededWithTheCurrentThemeAndInterval(): void
    {
        // A Daylight App whose config carries a non-default slideshow interval.
        $browse = $this->browsing()
            ->withTheme(Theme::daylight())
            ->withConfig((new Config('https://srv', 'Daylight', 9)));

        [$settings, $cmd] = $browse->update(new OpenSettingsMsg());

        self::assertSame(Route::Settings, $settings->route());
        $screen = $settings->screen();
        self::assertInstanceOf(SettingsScreen::class, $screen);
        self::assertSame(2, $settings->stackDepth(), 'settings is pushed onto Browse');
        // The form is seeded with the App's live theme + the config interval.
        self::assertSame('Daylight', $screen->currentTheme);
        self::assertSame(9, $screen->currentInterval);
        self::assertSame('9', $screen->form->getString('slideshow'), 'the interval pre-fills the form');
        // The App returns the form's focus Cmd on push; a Select-focused form has
        // no cursor-blink Cmd, so it is null here (the push still happened).
        self::assertSame($screen->init(), $cmd, 'the App threads the settings form init Cmd');
    }

    public function testSettingsSavedAppliesTheThemeLivePersistsAndPopsBack(): void
    {
        // Open settings on top of Browse, then save Midnight @ 12s.
        [$settings] = $this->browsing()->update(new OpenSettingsMsg());
        self::assertSame(2, $settings->stackDepth());

        [$saved, $cmd] = $settings->update(new SettingsSavedMsg('Midnight', 12));

        // The theme switches LIVE (the brand renders accent-wrapped now).
        self::assertSame('Midnight', $saved->theme()->name, 'the new theme applies live');
        self::assertMatchesRegularExpression('/\e\[[0-9;]*m Phlix \e\[0m/', $saved->view(), 'the live theme tints the brand');
        // The config carries the new theme + interval.
        self::assertSame('Midnight', $saved->config()->theme);
        self::assertSame(12, $saved->config()->slideshowInterval);
        // The settings frame is popped — we are back on Browse.
        self::assertSame(1, $saved->stackDepth(), 'the settings frame is popped');
        self::assertSame(Route::Browse, $saved->route());
        self::assertNull($cmd);
        // And it was persisted to disk (hermetic via the temp XDG_CONFIG_HOME).
        $onDisk = Config::load();
        self::assertSame('Midnight', $onDisk->theme, 'the theme was saved');
        self::assertSame(12, $onDisk->slideshowInterval, 'the interval was saved');
    }

    public function testSettingsSavedKeepsTheServerUrlInThePersistedConfig(): void
    {
        [$settings] = $this->browsing()->update(new OpenSettingsMsg());

        [$saved] = $settings->update(new SettingsSavedMsg('Daylight', 20));

        self::assertSame('https://srv', $saved->config()->serverUrl, 'saving settings keeps the server URL');
        self::assertSame('https://srv', Config::load()->serverUrl);
    }

    public function testSettingsSavedAppliesLiveEvenWhenPersistingFails(): void
    {
        // Occupy the config DIRECTORY path with a regular file so Config::save()
        // can't mkdir and throws — saveSettings swallows it (best-effort) and
        // still applies the theme + interval in memory.
        @mkdir($this->dir, 0o700, true);
        file_put_contents($this->dir . '/phlix', 'i am a file, not a dir');

        [$settings] = $this->browsing()->update(new OpenSettingsMsg());
        [$saved, $cmd] = $settings->update(new SettingsSavedMsg('Midnight', 15));

        self::assertSame('Midnight', $saved->theme()->name, 'the theme still applies live when the save fails');
        self::assertSame(15, $saved->config()->slideshowInterval, 'the interval still applies in memory');
        self::assertSame(1, $saved->stackDepth(), 'the settings frame is still popped');
        self::assertNull($cmd);

        @unlink($this->dir . '/phlix');
    }

    public function testWithConfigIsImmutableAndPreservesTheStack(): void
    {
        $browse = $this->browsing();
        $next = $browse->withConfig(new Config('https://srv', 'Midnight', 30));

        self::assertNotSame($browse, $next);
        self::assertSame(4, $browse->config()->slideshowInterval, 'the original app config is unchanged');
        self::assertSame(30, $next->config()->slideshowInterval);
        self::assertSame($browse->stackDepth(), $next->stackDepth(), 'the screen stack is preserved');
    }

    // ---- stats -----------------------------------------------------------

    public function testTheStaticPaletteActionsIncludeStats(): void
    {
        [$open] = $this->paletteApp()->update($this->ctrlK());

        $labels = array_map(static fn ($a): string => $a->label, $open->palette()->actions());
        self::assertContains('Stats', $labels, 'the command palette offers a Stats action');
    }

    public function testOpenStatsPushesTheStatsScreenWithAFetchCmd(): void
    {
        $browse = $this->browsing();
        self::assertSame(1, $browse->stackDepth());

        [$stats, $cmd] = $browse->update(new OpenStatsMsg());

        self::assertSame(Route::Stats, $stats->route());
        self::assertInstanceOf(StatsScreen::class, $stats->screen());
        self::assertSame(2, $stats->stackDepth(), 'stats is pushed onto Browse');
        self::assertInstanceOf(\Closure::class, $cmd, 'the stats screen fetches the libraries on push');
    }

    // ---- music audio (App-owned session) -------------------------------
    //
    // The music audio MOVED from AlbumScreen to the App, so playback persists
    // across navigation, shown by the NowPlayingBar. These tests are the relocated
    // audio suite, driven with a recording FakeAudioPlayer factory (capturing
    // URLs) over a FakeTransport serving /media/{id}.

    private const STREAM = 'https://srv/media/t1/stream?exp=1&sig=abc';

    /** The most recent fake audio player the App's factory produced. */
    private ?FakeAudioPlayer $lastAudioPlayer = null;
    /** Every URL the App's audio factory was handed (in order). @var list<string> */
    private array $audioUrls = [];

    private function audioAlbum(): Album
    {
        return Album::fromArray([
            'name' => 'Abbey Road',
            'artist' => 'The Beatles',
            'year' => 1969,
            'track_count' => 2,
            'tracks' => [
                ['id' => 't1', 'metadata' => ['title' => 'Come Together', 'track_number' => 1, 'duration_secs' => 259]],
                ['id' => 't2', 'metadata' => ['title' => 'Something', 'track_number' => 2, 'duration_secs' => 182]],
            ],
        ]);
    }

    /** A `/media/{id}` detail response carrying a (signed) stream URL. */
    private function itemResponse(?string $streamUrl): array
    {
        return ['item' => ['id' => 't1', 'name' => 'Come Together', 'type' => 'music', 'stream_url' => $streamUrl]];
    }

    /**
     * An App over a single Browse-like frame, wired to a real MediaStore (the
     * given FakeTransport, defaulting to a /media response with a signed URL) and
     * a recording audio factory (capturing URLs, no ffplay). The App owns the
     * music session, so the stack is irrelevant to playback.
     */
    private function audioApp(?FakeTransport $transport = null): App
    {
        $transport ??= (new FakeTransport())->json(200, $this->itemResponse(self::STREAM));
        $api = new ApiClient('https://srv', $transport);
        $factory = function (string $url, ?int $startMs = null): FakeAudioPlayer {
            $this->audioUrls[] = $url;

            return $this->lastAudioPlayer = new FakeAudioPlayer($url);
        };

        $app = new App(
            new Config('https://srv'),
            new AuthStore($api, TokenStore::default()),
            $api,
            new LibrariesStore($api),
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            [['route' => Route::Browse, 'screen' => new SpyTeardownScreen()]],
        );

        return $app->withAudioFactory($factory);
    }

    /** Drive PlayTrack → resolve → TrackResolved and return the now-playing App. */
    private function startPlaying(App $app, ?Album $album = null, int $index = 0): App
    {
        [$app2, $cmd] = $app->update(new PlayTrackMsg($album ?? $this->audioAlbum(), $index));
        $resolved = $this->runCmd($cmd);
        self::assertInstanceOf(TrackResolvedMsg::class, $resolved);
        [$playing] = $app2->update($resolved);

        return $playing;
    }

    public function testPlayTrackResolvesTheUrlSpawnsThePlayerAndShowsTheBar(): void
    {
        $app = $this->audioApp();

        // PlayTrack fetches the signed URL; the resolved Msg spawns the player.
        [$resolving, $cmd] = $app->update(new PlayTrackMsg($this->audioAlbum(), 0));
        $resolved = $this->runCmd($cmd);
        self::assertInstanceOf(TrackResolvedMsg::class, $resolved);
        self::assertSame(0, $resolved->index);
        self::assertSame(self::STREAM, $resolved->url, 'the signed absolute URL is used verbatim');

        [$playing, $tick] = $resolving->update($resolved);

        self::assertNotNull($this->lastAudioPlayer);
        self::assertSame(1, $this->lastAudioPlayer->startCalls, 'the player was started');
        self::assertNotNull($tick, 'the position heartbeat is armed');
        // Cmd::tick returns a TickRequest carrying the producer of the heartbeat Msg.
        $request = $tick();
        self::assertInstanceOf(\SugarCraft\Core\TickRequest::class, $request);
        self::assertInstanceOf(NowPlayingTickMsg::class, ($request->produce)());

        // The now-playing bar appears on the bottom row of the view.
        $view = $playing->view();
        self::assertStringContainsString('▶ Come Together', $view);
        self::assertStringContainsString('Abbey Road · The Beatles', $view);
        self::assertStringContainsString('0:00 / 4:19', $view);
    }

    public function testPlayTrackWithAnOutOfRangeIndexIsANoOp(): void
    {
        $app = $this->audioApp();

        [$same, $cmd] = $app->update(new PlayTrackMsg($this->audioAlbum(), 99));

        self::assertSame($app, $same, 'an out-of-range track index does nothing');
        self::assertNull($cmd, 'no fetch is fired');
        self::assertNull($app->nowPlaying());
    }

    public function testARelativeStreamUrlIsResolvedAgainstTheServerBase(): void
    {
        $app = $this->audioApp((new FakeTransport())->json(200, $this->itemResponse('/media/t1/stream?sig=x')));

        [, $cmd] = $app->update(new PlayTrackMsg($this->audioAlbum(), 0));
        $resolved = $this->runCmd($cmd);

        self::assertInstanceOf(TrackResolvedMsg::class, $resolved);
        self::assertSame('https://srv/media/t1/stream?sig=x', $resolved->url);
    }

    public function testToggleAudioPausesAndResumes(): void
    {
        $playing = $this->startPlaying($this->audioApp());
        self::assertStringContainsString('▶ Come Together', $playing->view());

        // Pause: the glyph flips, the player is paused, the tick stops.
        [$paused, $pauseCmd] = $playing->update(new ToggleAudioMsg());
        self::assertSame(1, $this->lastAudioPlayer?->pauseCalls);
        self::assertNull($pauseCmd, 'pausing stops the heartbeat');
        self::assertStringContainsString('⏸ Come Together', $paused->view());

        // Resume: the player resumes and a fresh heartbeat re-arms.
        [$resumed, $resumeCmd] = $paused->update(new ToggleAudioMsg());
        self::assertSame(1, $this->lastAudioPlayer?->resumeCalls);
        self::assertNotNull($resumeCmd, 'resuming re-arms the heartbeat');
        self::assertStringContainsString('▶ Come Together', $resumed->view());
    }

    public function testToggleAudioWithNothingPlayingIsANoOp(): void
    {
        $app = $this->audioApp();

        [$same, $cmd] = $app->update(new ToggleAudioMsg());

        self::assertSame($app, $same);
        self::assertNull($cmd);
    }

    public function testNowPlayingTickAdvancesTheEstimatedPosition(): void
    {
        $cur = $this->startPlaying($this->audioApp());
        for ($i = 0; $i < 5; $i++) {
            [$cur, $cmd] = $cur->update(new NowPlayingTickMsg($this->epochOf($cur)));
            self::assertNotNull($cmd, 'each playing tick re-arms the next');
        }

        self::assertStringContainsString('0:05 / 4:19', $cur->view(), 'position renders as m:ss');
    }

    public function testATickWhilePausedDoesNotAdvanceOrRearm(): void
    {
        $playing = $this->startPlaying($this->audioApp());
        [$paused] = $playing->update(new ToggleAudioMsg());

        [$same, $cmd] = $paused->update(new NowPlayingTickMsg($this->epochOf($paused)));

        self::assertNull($cmd, 'no re-arm while paused');
        self::assertStringContainsString('0:00 /', $same->view(), 'the position did not advance');
    }

    public function testATickWithNothingPlayingIsANoOp(): void
    {
        $app = $this->audioApp();

        [$same, $cmd] = $app->update(new NowPlayingTickMsg(0));

        self::assertSame($app, $same);
        self::assertNull($cmd);
    }

    public function testAStaleTickFromASupersededGenerationIsDropped(): void
    {
        // Regression (relocated from AlbumScreen): a leftover tick from a previous
        // heartbeat must NOT advance the position or arm a second heartbeat (which
        // would double playback speed + auto-advance early). Reproduced via a
        // pause/resume cycle, which supersedes the running chain.
        $cur = $this->startPlaying($this->audioApp());
        $staleEpoch = $this->epochOf($cur);
        [$cur] = $cur->update(new NowPlayingTickMsg($this->epochOf($cur))); // position 1, same generation

        [$paused] = $cur->update(new ToggleAudioMsg());   // bump epoch, pause
        [$resumed, $arm] = $paused->update(new ToggleAudioMsg()); // bump epoch, resume + new heartbeat
        self::assertNotNull($arm, 'resume arms a fresh heartbeat');
        self::assertNotSame($staleEpoch, $this->epochOf($resumed), 'the generation advanced');

        $beforePos = $this->positionOf($resumed);

        // The leftover tick from the original generation is ignored.
        [$afterStale, $staleCmd] = $resumed->update(new NowPlayingTickMsg($staleEpoch));
        self::assertSame($beforePos, $this->positionOf($afterStale), 'a stale tick does not advance the position');
        self::assertNull($staleCmd, 'a stale tick does not arm a second heartbeat');

        // The live generation's tick still advances exactly once (+1, not +2).
        [$afterLive] = $resumed->update(new NowPlayingTickMsg($this->epochOf($resumed)));
        self::assertSame($beforePos + 1, $this->positionOf($afterLive));
    }

    public function testReachingTheDurationAutoAdvancesToTheNextTrack(): void
    {
        // A 2-second track followed by another, so the tick reaches the duration fast.
        $album = Album::fromArray([
            'name' => 'Short',
            'tracks' => [
                ['id' => 't1', 'metadata' => ['title' => 'One', 'duration_secs' => 2]],
                ['id' => 't2', 'metadata' => ['title' => 'Two', 'duration_secs' => 5]],
            ],
        ]);
        // Two item fetches: the first track (on PlayTrack) then the second (on advance).
        $transport = (new FakeTransport())
            ->json(200, ['item' => ['id' => 't1', 'name' => 'One', 'type' => 'music', 'stream_url' => 'https://srv/s/t1']])
            ->json(200, ['item' => ['id' => 't2', 'name' => 'Two', 'type' => 'music', 'stream_url' => 'https://srv/s/t2']]);
        $cur = $this->startPlaying($this->audioApp($transport), $album, 0);
        $firstPlayer = $this->lastAudioPlayer;
        self::assertStringContainsString('▶ One', $cur->view());

        // Tick to 2s == duration → auto-advance fetch fires.
        [$cur] = $cur->update(new NowPlayingTickMsg($this->epochOf($cur))); // 1
        [$advancing, $advanceCmd] = $cur->update(new NowPlayingTickMsg($this->epochOf($cur))); // 2 == duration → advance
        $next = $this->runCmd($advanceCmd);
        self::assertInstanceOf(TrackResolvedMsg::class, $next, 'reaching the duration starts the next track');
        self::assertSame(1, $next->index);

        [$onTwo] = $advancing->update($next);
        self::assertNotNull($firstPlayer);
        self::assertSame(1, $firstPlayer->stopCalls, 'the previous player was stopped');
        self::assertStringContainsString('▶ Two', $onTwo->view());
        self::assertStringContainsString('0:00 / 0:05', $onTwo->view(), 'position resets on the new track');
    }

    public function testReachingTheDurationOnTheLastTrackStopsPlayback(): void
    {
        $album = Album::fromArray([
            'name' => 'Solo',
            'tracks' => [['id' => 't1', 'metadata' => ['title' => 'Only', 'duration_secs' => 2]]],
        ]);
        $transport = (new FakeTransport())->json(200, ['item' => ['id' => 't1', 'name' => 'Only', 'type' => 'music', 'stream_url' => 'https://srv/s/t1']]);
        $cur = $this->startPlaying($this->audioApp($transport), $album, 0);
        $player = $this->lastAudioPlayer;

        [$cur] = $cur->update(new NowPlayingTickMsg($this->epochOf($cur))); // 1
        [$stopped, $cmd] = $cur->update(new NowPlayingTickMsg($this->epochOf($cur))); // 2 == duration, no next → stop

        self::assertNull($cmd, 'no further tick once stopped');
        self::assertSame(1, $player?->stopCalls);
        // The now-playing bar is gone — there is no glyph left in the view.
        self::assertStringNotContainsString('▶', $stopped->view());
        self::assertStringNotContainsString('⏸', $stopped->view());
    }

    public function testAnUnknownDurationTrackNeverAutoAdvances(): void
    {
        $album = Album::fromArray([
            'name' => 'Live',
            'tracks' => [
                ['id' => 't1', 'metadata' => ['title' => 'Jam']], // no duration_secs
                ['id' => 't2', 'metadata' => ['title' => 'Next', 'duration_secs' => 3]],
            ],
        ]);
        $cur = $this->startPlaying($this->audioApp(), $album, 0);

        for ($i = 0; $i < 50; $i++) {
            [$cur, $cmd] = $cur->update(new NowPlayingTickMsg($this->epochOf($cur)));
            self::assertNotNull($cmd, 'an unknown-duration track keeps ticking');
        }

        self::assertStringContainsString('▶ Jam', $cur->view(), 'still on the first track');
        self::assertStringContainsString('0:50 / —', $cur->view(), 'unknown duration shows a dash');
    }

    public function testAudioSkipNextAndPrevious(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['item' => ['id' => 't1', 'name' => 'Come Together', 'type' => 'music', 'stream_url' => 'https://srv/s/t1']])
            ->json(200, ['item' => ['id' => 't2', 'name' => 'Something', 'type' => 'music', 'stream_url' => 'https://srv/s/t2']])
            ->json(200, ['item' => ['id' => 't1', 'name' => 'Come Together', 'type' => 'music', 'stream_url' => 'https://srv/s/t1']]);
        $cur = $this->startPlaying($this->audioApp($transport));
        self::assertStringContainsString('▶ Come Together', $cur->view());

        // n → next track.
        [$skipping, $cmd] = $cur->update(new AudioSkipMsg(1));
        $next = $this->runCmd($cmd);
        self::assertInstanceOf(TrackResolvedMsg::class, $next);
        self::assertSame(1, $next->index);
        [$onTwo] = $skipping->update($next);
        self::assertStringContainsString('▶ Something', $onTwo->view());

        // p → previous track.
        [$back, $backCmd] = $onTwo->update(new AudioSkipMsg(-1));
        $prev = $this->runCmd($backCmd);
        self::assertInstanceOf(TrackResolvedMsg::class, $prev);
        self::assertSame(0, $prev->index);
    }

    public function testAudioSkipPastTheEndsIsANoOp(): void
    {
        // Playing the (last) second track: skipping forward is a no-op.
        $cur = $this->startPlaying($this->audioApp(), $this->audioAlbum(), 1);
        self::assertStringContainsString('▶ Something', $cur->view());

        [$same, $cmd] = $cur->update(new AudioSkipMsg(1));
        self::assertSame($cur, $same, 'no next track to skip to');
        self::assertNull($cmd);

        // And from the first track, skipping backward is a no-op too.
        $first = $this->startPlaying($this->audioApp(), $this->audioAlbum(), 0);
        [$same2, $cmd2] = $first->update(new AudioSkipMsg(-1));
        self::assertSame($first, $same2);
        self::assertNull($cmd2);
    }

    public function testAudioSkipWithNothingPlayingIsANoOp(): void
    {
        $app = $this->audioApp();

        [$same, $cmd] = $app->update(new AudioSkipMsg(1));

        self::assertSame($app, $same);
        self::assertNull($cmd);
    }

    public function testStopAudioClearsTheSessionAndStopsThePlayer(): void
    {
        $playing = $this->startPlaying($this->audioApp());
        $player = $this->lastAudioPlayer;
        self::assertStringContainsString('▶ Come Together', $playing->view());

        [$stopped, $cmd] = $playing->update(new StopAudioMsg());

        self::assertNull($cmd);
        self::assertSame(1, $player?->stopCalls, 'stop tears the player down');
        self::assertStringNotContainsString('▶ Come Together', $stopped->view(), 'the bar is gone');
    }

    public function testAMissingStreamUrlSurfacesAToastAndDoesNotDisturbPlayback(): void
    {
        // Already playing the first track; a later failed start must not stop it.
        $transport = (new FakeTransport())
            ->json(200, $this->itemResponse(self::STREAM))      // first play OK
            ->json(200, $this->itemResponse(null));             // second play: no URL
        $playing = $this->startPlaying($this->audioApp($transport));
        $livePlayer = $this->lastAudioPlayer;

        // Try to play a track whose detail has no stream URL.
        [$app2, $cmd] = $playing->update(new PlayTrackMsg($this->audioAlbum(), 1));
        $failMsg = $this->runCmd($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $failMsg);
        self::assertSame(ToastType::Error, $failMsg->type);
        self::assertStringContainsString('Could not play', $failMsg->message);

        // The original track is still playing (its player was never stopped).
        self::assertSame(0, $livePlayer?->stopCalls ?? -1, 'the live player was not stopped');
        self::assertStringContainsString('▶ Come Together', $app2->view());
    }

    public function testAFetchFailureSurfacesAToast(): void
    {
        $app = $this->audioApp((new FakeTransport())->fail(new \RuntimeException('network')));

        [, $cmd] = $app->update(new PlayTrackMsg($this->audioAlbum(), 0));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $msg);
        self::assertStringContainsString('Could not play', $msg->message);
    }

    public function testAnAuthErrorBecomesSessionExpired(): void
    {
        $app = $this->audioApp((new FakeTransport())->json(401, ['error' => 'unauthorized']));

        [, $cmd] = $app->update(new PlayTrackMsg($this->audioAlbum(), 0));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    public function testPlayingATrackThenNavigatingAwayKeepsTheAudio(): void
    {
        // THE persist-across-navigation proof: play a track on top of a deeper
        // stack, then NavigateBack — the session (and the bar) survive the pop.
        $api = new ApiClient('https://srv', (new FakeTransport())->json(200, $this->itemResponse(self::STREAM)));
        $factory = function (string $url, ?int $startMs = null): FakeAudioPlayer {
            return $this->lastAudioPlayer = new FakeAudioPlayer($url);
        };
        $app = (new App(
            new Config('https://srv'),
            new AuthStore($api, TokenStore::default()),
            $api,
            new LibrariesStore($api),
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            [
                ['route' => Route::Browse, 'screen' => new SpyTeardownScreen()],
                ['route' => Route::Album, 'screen' => new AlbumScreen($this->audioAlbum(), cols: 80, rows: 24)],
            ],
        ))->withAudioFactory($factory);

        $playing = $this->startPlaying($app);
        self::assertSame(2, $playing->stackDepth());
        self::assertStringContainsString('▶ Come Together', $playing->view());

        // Leave the album.
        [$back] = $playing->update(new NavigateBackMsg());

        self::assertSame(1, $back->stackDepth(), 'the album frame was popped');
        self::assertSame(Route::Browse, $back->route());
        self::assertSame(0, $this->lastAudioPlayer?->stopCalls, 'leaving the album does NOT stop the audio');
        self::assertStringContainsString('▶ Come Together', $back->view(), 'the now-playing bar persists on the screen beneath');
    }

    public function testCtrlCTearsDownTheActiveAudio(): void
    {
        $playing = $this->startPlaying($this->audioApp());
        $player = $this->lastAudioPlayer;

        [, $cmd] = $playing->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        self::assertInstanceOf(QuitMsg::class, $cmd?->__invoke());
        self::assertSame(1, $player?->stopCalls, 'Ctrl-C stops the music (no leaked ffplay)');
    }

    public function testRequestQuitTearsDownTheActiveAudio(): void
    {
        $playing = $this->startPlaying($this->audioApp());
        $player = $this->lastAudioPlayer;

        [, $cmd] = $playing->update(new RequestQuitMsg());

        self::assertInstanceOf(QuitMsg::class, $cmd?->__invoke());
        self::assertSame(1, $player?->stopCalls, 'quitting stops the music');
    }

    public function testThePaletteGainsPauseAndStopActionsWhilePlaying(): void
    {
        $playing = $this->startPlaying($this->audioApp());

        [$open] = $playing->update($this->ctrlK());
        $labels = array_map(static fn ($a): string => $a->label, $open->palette()->actions());

        self::assertContains('Pause playback', $labels, 'a playing session adds a Pause action');
        self::assertContains('Stop playback', $labels);
    }

    public function testThePalettePauseActionBecomesResumeWhenPaused(): void
    {
        $playing = $this->startPlaying($this->audioApp());
        [$paused] = $playing->update(new ToggleAudioMsg());

        [$open] = $paused->update($this->ctrlK());
        $labels = array_map(static fn ($a): string => $a->label, $open->palette()->actions());

        self::assertContains('Resume playback', $labels);
        self::assertNotContains('Pause playback', $labels);
    }

    public function testThePaletteHasNoAudioActionsWhenNothingPlays(): void
    {
        [$open] = $this->audioApp()->update($this->ctrlK());

        $labels = array_map(static fn ($a): string => $a->label, $open->palette()->actions());
        self::assertNotContains('Pause playback', $labels);
        self::assertNotContains('Stop playback', $labels);
        self::assertNotContains('Resume playback', $labels);
    }

    /** The active session's epoch (read off the now-playing bar via the App view is fragile, so go through a tick probe). */
    private function epochOf(App $app): int
    {
        return $app->nowPlaying()?->epoch() ?? 0;
    }

    private function positionOf(App $app): int
    {
        $session = $app->nowPlaying();

        return $session instanceof MusicSession ? $session->positionSecs() : 0;
    }

    // ---- audiobook audio (App-owned session) ---------------------------
    //
    // The audiobook audio MOVED from AudiobookDetailScreen to the App (T3b), so an
    // audiobook persists across navigation, shown by the same NowPlayingBar.
    // These tests are the relocated audiobook audio suite, driven with a recording
    // FakeAudioPlayer factory (capturing [url, startMs]) over a FakeTransport
    // serving /audiobooks/{id}/progress for the throttled progress POSTs. Play is
    // SYNCHRONOUS — the stream URL rides on the PlayAudiobookMsg (no fetch).

    private const AB_STREAM = 'https://srv/api/v1/audiobooks/ab1/stream?sig=s';

    /** The most recent fake audio player the App's factory produced (audiobook suite). */
    private ?FakeAudioPlayer $lastAbPlayer = null;
    /** Every [url, startMs] the App's audio factory was handed (audiobook suite). @var list<array{0:string,1:?int}> */
    private array $abPlays = [];

    private function audiobook(array $overrides = []): Audiobook
    {
        return Audiobook::fromArray(array_merge([
            'id' => 'ab1',
            'title' => 'Dune',
            'author' => 'Frank Herbert',
            'narrator' => 'Scott Brick',
            'duration_ms' => 75_600_000, // 21:00:00
            'stream_url' => self::AB_STREAM,
        ], $overrides));
    }

    /** Two chapters: [0, 3_600_000) "Beginnings", [3_600_000, 7_200_000) "The Spice". @return list<AudiobookChapter> */
    private function audiobookChapters(): array
    {
        return [
            AudiobookChapter::fromArray(['index' => 0, 'title' => 'Beginnings', 'start_ms' => 0, 'end_ms' => 3_600_000, 'duration_ms' => 3_600_000], 0),
            AudiobookChapter::fromArray(['index' => 1, 'title' => 'The Spice', 'start_ms' => 3_600_000, 'end_ms' => 7_200_000, 'duration_ms' => 3_600_000], 1),
        ];
    }

    /**
     * An App over a single Browse-like frame wired to a recording audio factory.
     * The transport (defaulting to one empty progress response) serves the
     * fire-and-forget progress POSTs the App's AudiobooksStore makes.
     */
    private function audiobookApp(?FakeTransport $transport = null): App
    {
        $transport ??= (new FakeTransport())->json(200, ['progress' => []]);
        $api = new ApiClient('https://srv', $transport);
        $factory = function (string $url, ?int $startMs = null): FakeAudioPlayer {
            $this->abPlays[] = [$url, $startMs];

            return $this->lastAbPlayer = new FakeAudioPlayer($url);
        };

        $app = new App(
            new Config('https://srv'),
            new AuthStore($api, TokenStore::default()),
            $api,
            new LibrariesStore($api),
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            [['route' => Route::Browse, 'screen' => new SpyTeardownScreen()]],
        );

        return $app->withAudioFactory($factory);
    }

    /** Play an audiobook (synchronous) at $startMs and return the now-playing App. */
    private function startAudiobook(App $app, ?Audiobook $book = null, ?array $chapters = null, int $startMs = 0): App
    {
        [$playing, $tick] = $app->update(new PlayAudiobookMsg($book ?? $this->audiobook(), $chapters ?? $this->audiobookChapters(), $startMs));
        self::assertNotNull($tick, 'playing arms the audiobook heartbeat');

        return $playing;
    }

    private function abEpochOf(App $app): int
    {
        return $app->nowPlaying()?->epoch() ?? 0;
    }

    private function abPositionOf(App $app): int
    {
        $session = $app->nowPlaying();

        return $session instanceof AudiobookSession ? $session->positionMs() : -1;
    }

    public function testPlayAudiobookSpawnsThePlayerAtStartMsSynchronouslyAndShowsTheBar(): void
    {
        $app = $this->audiobookApp();

        // Play the second chapter (starts at 1h) → synchronous spawn at 3_600_000ms.
        [$playing, $tick] = $app->update(new PlayAudiobookMsg($this->audiobook(), $this->audiobookChapters(), 3_600_000));

        self::assertCount(1, $this->abPlays, 'the factory was called immediately (no fetch)');
        self::assertSame(self::AB_STREAM, $this->abPlays[0][0], 'the signed absolute URL is used verbatim');
        self::assertSame(3_600_000, $this->abPlays[0][1], 'the player seeks to the chapter start');
        self::assertNotNull($this->lastAbPlayer);
        self::assertSame(1, $this->lastAbPlayer->startCalls, 'the player was started');

        // The heartbeat armed is the DEDICATED audiobook tick.
        self::assertNotNull($tick);
        $request = $tick();
        self::assertInstanceOf(\SugarCraft\Core\TickRequest::class, $request);
        self::assertInstanceOf(AudiobookTickMsg::class, ($request->produce)());

        // The now-playing bar shows the current chapter + the ms clock.
        $view = $playing->view();
        self::assertStringContainsString('▶ The Spice', $view);
        self::assertStringContainsString('1:00:00 / 21:00:00', $view);
        self::assertInstanceOf(AudiobookSession::class, $playing->nowPlaying());
    }

    public function testPlayAudiobookFromZeroOnAChapterlessBook(): void
    {
        $app = $this->audiobookApp();

        [$playing] = $app->update(new PlayAudiobookMsg($this->audiobook(), [], 0));

        self::assertCount(1, $this->abPlays);
        self::assertSame(0, $this->abPlays[0][1], 'from the very start');
        // The bar falls back to the audiobook title (no chapters).
        self::assertStringContainsString('▶ Dune', $playing->view());
    }

    public function testARelativeAudiobookStreamUrlIsResolvedAgainstTheBase(): void
    {
        $app = $this->audiobookApp();

        $app->update(new PlayAudiobookMsg($this->audiobook(['stream_url' => '/api/v1/audiobooks/ab1/stream?sig=x']), $this->audiobookChapters(), 0));

        self::assertSame('https://srv/api/v1/audiobooks/ab1/stream?sig=x', $this->abPlays[0][0]);
    }

    public function testAMissingAudiobookStreamUrlSurfacesAToastAndPlaysNothing(): void
    {
        $app = $this->audiobookApp();

        [$after, $cmd] = $app->update(new PlayAudiobookMsg($this->audiobook(['stream_url' => null]), $this->audiobookChapters(), 0));

        $toast = $cmd?->__invoke();
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Cannot play', $toast->message);
        self::assertNull($after->nowPlaying(), 'nothing starts playing');
        self::assertCount(0, $this->abPlays, 'the factory is never called');
    }

    public function testToggleAudioPausesAndResumesAnAudiobookReArmingTheAudiobookTick(): void
    {
        $transport = (new FakeTransport())->json(200, ['progress' => []]); // the pause-save POST
        $playing = $this->startAudiobook($this->audiobookApp($transport));
        self::assertStringContainsString('▶ Beginnings', $playing->view());

        // Pause: glyph flips, the player is paused, the heartbeat stops, AND the
        // current position is persisted (so a pause-then-quit resumes exactly here).
        [$paused, $pauseCmd] = $playing->update(new ToggleAudioMsg());
        self::assertSame(1, $this->lastAbPlayer?->pauseCalls);
        self::assertStringContainsString('⏸ Beginnings', $paused->view());
        self::assertNotNull($pauseCmd, 'pausing an audiobook persists its position (fire-and-forget save)');
        $this->runCmd($pauseCmd);
        self::assertSame(1, $transport->requestCount(), 'pause saves progress');
        self::assertStringEndsWith('/api/v1/audiobooks/ab1/progress', $transport->requestAt(0)['url']);

        // Resume: the player resumes and a fresh AUDIOBOOK heartbeat re-arms (no save).
        [$resumed, $resumeCmd] = $paused->update(new ToggleAudioMsg());
        self::assertSame(1, $transport->requestCount(), 'resuming does not POST again');
        self::assertSame(1, $this->lastAbPlayer?->resumeCalls);
        self::assertNotNull($resumeCmd, 'resuming re-arms the heartbeat');
        $request = $resumeCmd();
        self::assertInstanceOf(AudiobookTickMsg::class, ($request->produce)(), 'resume re-arms the AUDIOBOOK tick, not the music one');
        self::assertStringContainsString('▶ Beginnings', $resumed->view());
    }

    public function testAudiobookTickAdvancesThePositionByOneSecond(): void
    {
        $cur = $this->startAudiobook($this->audiobookApp());
        for ($i = 0; $i < 5; $i++) {
            [$cur, $cmd] = $cur->update(new AudiobookTickMsg($this->abEpochOf($cur)));
            self::assertNotNull($cmd, 'each playing tick re-arms the next');
        }

        self::assertSame(5000, $this->abPositionOf($cur), 'five 1-second ticks = 5000ms');
        self::assertStringContainsString('0:05 / 21:00:00', $cur->view(), 'position renders as a clock');
    }

    public function testAnAudiobookTickWhilePausedIsInert(): void
    {
        $playing = $this->startAudiobook($this->audiobookApp());
        [$paused] = $playing->update(new ToggleAudioMsg());

        [$same, $cmd] = $paused->update(new AudiobookTickMsg($this->abEpochOf($paused)));

        self::assertNull($cmd, 'no re-arm while paused');
        self::assertSame(0, $this->abPositionOf($same), 'the position did not advance');
    }

    public function testAnAudiobookTickWithNothingPlayingIsANoOp(): void
    {
        $app = $this->audiobookApp();

        [$same, $cmd] = $app->update(new AudiobookTickMsg(0));

        self::assertSame($app, $same);
        self::assertNull($cmd);
    }

    public function testAStaleAudiobookTickFromASupersededGenerationIsDropped(): void
    {
        // Regression (relocated from AudiobookDetailScreen): a leftover tick from a
        // previous heartbeat must NOT advance the position or arm a second one.
        $cur = $this->startAudiobook($this->audiobookApp());
        $staleEpoch = $this->abEpochOf($cur);
        [$cur] = $cur->update(new AudiobookTickMsg($this->abEpochOf($cur))); // +1000ms, same generation

        [$paused] = $cur->update(new ToggleAudioMsg());          // bump epoch, pause
        [$resumed, $arm] = $paused->update(new ToggleAudioMsg()); // bump epoch, resume + new heartbeat
        self::assertNotNull($arm, 'resume arms a fresh heartbeat');
        self::assertNotSame($staleEpoch, $this->abEpochOf($resumed), 'the generation advanced');

        $beforePos = $this->abPositionOf($resumed);

        // The leftover tick from the original generation is ignored.
        [$afterStale, $staleCmd] = $resumed->update(new AudiobookTickMsg($staleEpoch));
        self::assertSame($beforePos, $this->abPositionOf($afterStale), 'a stale tick does not advance');
        self::assertNull($staleCmd, 'a stale tick does not arm a second heartbeat');

        // The live generation's tick still advances exactly once (+1000ms, not +2000).
        [$afterLive] = $resumed->update(new AudiobookTickMsg($this->abEpochOf($resumed)));
        self::assertSame($beforePos + 1000, $this->abPositionOf($afterLive));
    }

    public function testEveryTenTicksPostsAThrottledProgressSave(): void
    {
        $transport = (new FakeTransport())->json(200, ['progress' => []]); // the throttled save POST
        $cur = $this->startAudiobook($this->audiobookApp($transport));

        // Nine ticks: no save yet (each re-arms only).
        for ($i = 0; $i < 9; $i++) {
            [$cur, $cmd] = $cur->update(new AudiobookTickMsg($this->abEpochOf($cur)));
            self::assertNotNull($cmd);
            self::assertSame(0, $transport->requestCount(), 'no save before the 10th tick');
        }

        // The 10th tick's Cmd is a batch (tick + report) → it POSTs the save.
        [$cur, $tenth] = $cur->update(new AudiobookTickMsg($this->abEpochOf($cur)));
        self::assertSame(10_000, $this->abPositionOf($cur));
        $this->drainBatchCmds($tenth);

        self::assertSame(1, $transport->requestCount(), 'the 10th tick saves progress');
        $post = $transport->requestAt(0);
        self::assertSame('POST', $post['method']);
        self::assertStringEndsWith('/api/v1/audiobooks/ab1/progress', $post['url']);
        $body = json_decode($post['body'], true);
        self::assertSame(10_000, $body['position_ms']);
        self::assertSame(0, $body['current_chapter_index'], '10s in → still chapter 0');
        self::assertSame([], $body['completed_chapters'], 'no chapter finished yet');
        self::assertEqualsWithDelta(10_000 / 75_600_000 * 100, $body['percent_complete'], 0.0001);
    }

    public function testReachingTheDurationStopsAndFiresAFinalReport(): void
    {
        // A tiny audiobook (2-second duration) so the tick reaches the end fast.
        $transport = (new FakeTransport())->json(200, ['progress' => []]); // the final save POST
        $book = $this->audiobook(['duration_ms' => 2000]);
        $chapters = [AudiobookChapter::fromArray(['index' => 0, 'title' => 'Only', 'start_ms' => 0, 'end_ms' => 2000, 'duration_ms' => 2000], 0)];
        $cur = $this->startAudiobook($this->audiobookApp($transport), $book, $chapters, 0);
        $player = $this->lastAbPlayer;

        [$cur, $cmd1] = $cur->update(new AudiobookTickMsg($this->abEpochOf($cur))); // 1000ms
        self::assertNotNull($cmd1);
        [$finished, $finalCmd] = $cur->update(new AudiobookTickMsg($this->abEpochOf($cur))); // 2000ms == duration → finish

        self::assertNull($finished->nowPlaying(), 'the session is cleared at the end');
        self::assertSame(1, $player?->stopCalls, 'the player is stopped');
        self::assertInstanceOf(\Closure::class, $finalCmd, 'a final report fires');
        $this->runCmd($finalCmd);

        $post = $transport->requestAt(0);
        self::assertSame('POST', $post['method']);
        $body = json_decode($post['body'], true);
        self::assertSame(2000, $body['position_ms']);
        self::assertEqualsWithDelta(100.0, $body['percent_complete'], 0.0001, 'a finished book reports ~100%');
        self::assertSame([0], $body['completed_chapters'], 'the only chapter is complete');

        // The bar is gone.
        self::assertStringNotContainsString('▶', $finished->view());
        self::assertStringNotContainsString('⏸', $finished->view());
    }

    public function testResumePlaysAnAudiobookFromASavedPosition(): void
    {
        // A PlayAudiobookMsg at the saved resume position (what the `r` key emits).
        $app = $this->audiobookApp();

        [$playing] = $app->update(new PlayAudiobookMsg($this->audiobook(), $this->audiobookChapters(), 5_400_000));

        self::assertSame(5_400_000, $this->abPlays[0][1], 'resume seeks to the saved position');
        self::assertSame(5_400_000, $this->abPositionOf($playing));
        self::assertStringContainsString('1:30:00 /', $playing->view());
    }

    public function testStopAudioClearsAnAudiobookSessionAndStopsThePlayer(): void
    {
        $playing = $this->startAudiobook($this->audiobookApp());
        $player = $this->lastAbPlayer;
        self::assertStringContainsString('▶ Beginnings', $playing->view());

        [$stopped, $cmd] = $playing->update(new StopAudioMsg());

        self::assertNull($cmd);
        self::assertSame(1, $player?->stopCalls, 'stop tears the player down');
        self::assertNull($stopped->nowPlaying(), 'the session is cleared');
        self::assertStringNotContainsString('▶ Beginnings', $stopped->view(), 'the bar is gone');
    }

    public function testPlayingAnAudiobookThenNavigatingAwayKeepsTheSession(): void
    {
        // THE persist-across-navigation proof for audiobooks: play on top of a
        // deeper stack (Browse → AudiobookDetail), then NavigateBack — the session
        // (and the bar) survive the pop, and nothing is torn down.
        $api = new ApiClient('https://srv', (new FakeTransport())->json(200, ['progress' => []]));
        $factory = function (string $url, ?int $startMs = null): FakeAudioPlayer {
            return $this->lastAbPlayer = new FakeAudioPlayer($url);
        };
        $app = (new App(
            new Config('https://srv'),
            new AuthStore($api, TokenStore::default()),
            $api,
            new LibrariesStore($api),
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            [
                ['route' => Route::Browse, 'screen' => new SpyTeardownScreen()],
                ['route' => Route::AudiobookDetail, 'screen' => new AudiobookDetailScreen(new AudiobooksStore($api), 'ab1', 'Dune', cols: 80, rows: 24)],
            ],
        ))->withAudioFactory($factory);

        $playing = $this->startAudiobook($app);
        self::assertSame(2, $playing->stackDepth());
        self::assertStringContainsString('▶ Beginnings', $playing->view());

        // Leave the audiobook detail.
        [$back] = $playing->update(new NavigateBackMsg());

        self::assertSame(1, $back->stackDepth(), 'the audiobook detail frame was popped');
        self::assertSame(Route::Browse, $back->route());
        self::assertSame(0, $this->lastAbPlayer?->stopCalls, 'leaving does NOT stop the audiobook');
        self::assertStringContainsString('▶ Beginnings', $back->view(), 'the now-playing bar persists on the screen beneath');
    }

    public function testCtrlCTearsDownTheActiveAudiobook(): void
    {
        $playing = $this->startAudiobook($this->audiobookApp());
        $player = $this->lastAbPlayer;

        [, $cmd] = $playing->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        self::assertInstanceOf(QuitMsg::class, $cmd?->__invoke());
        self::assertSame(1, $player?->stopCalls, 'Ctrl-C stops the audiobook (no leaked ffplay)');
    }

    public function testRequestQuitTearsDownTheActiveAudiobook(): void
    {
        $playing = $this->startAudiobook($this->audiobookApp());
        $player = $this->lastAbPlayer;

        [, $cmd] = $playing->update(new RequestQuitMsg());

        self::assertInstanceOf(QuitMsg::class, $cmd?->__invoke());
        self::assertSame(1, $player?->stopCalls, 'quitting stops the audiobook');
    }

    public function testAMusicTickIsIgnoredByAnAudiobookSession(): void
    {
        // The two tick kinds never cross-fire: a NowPlayingTickMsg must not advance
        // (or disturb) an active audiobook session.
        $playing = $this->startAudiobook($this->audiobookApp());

        [$same, $cmd] = $playing->update(new NowPlayingTickMsg($this->abEpochOf($playing)));

        self::assertNull($cmd, 'a music tick arms nothing on an audiobook');
        self::assertSame(0, $this->abPositionOf($same), 'the audiobook position is untouched');
    }

    public function testAnAudiobookTickIsIgnoredByAMusicSession(): void
    {
        // The mirror: an AudiobookTickMsg must not advance an active music session.
        $playing = $this->startPlaying($this->audioApp());

        [$same, $cmd] = $playing->update(new AudiobookTickMsg($this->epochOf($playing)));

        self::assertNull($cmd, 'an audiobook tick arms nothing on a music session');
        self::assertSame(0, $this->positionOf($same), 'the music position is untouched');
    }

    public function testThePaletteGainsPauseAndStopWhileAnAudiobookPlays(): void
    {
        $playing = $this->startAudiobook($this->audiobookApp());

        [$open] = $playing->update($this->ctrlK());
        $labels = array_map(static fn ($a): string => $a->label, $open->palette()->actions());

        self::assertContains('Pause playback', $labels, 'a playing audiobook adds a Pause action');
        self::assertContains('Stop playback', $labels);
    }

    // ---- metrics / HUD overlay -----------------------------------------

    /**
     * A now-playing music App over a REAL, full-height BrowseScreen (not the
     * empty teardown spy) so the HUD (top-left) and the now-playing bar (bottom
     * row) occupy different rows — proving they coexist on a normal terminal.
     */
    private function playingMusicApp(): App
    {
        $transport = (new FakeTransport())->json(200, $this->itemResponse(self::STREAM));
        $api = new ApiClient('https://srv', $transport);
        $factory = function (string $url, ?int $startMs = null): FakeAudioPlayer {
            return $this->lastAudioPlayer = new FakeAudioPlayer($url);
        };
        $browse = new BrowseScreen(
            AuthUser::fromArray(['id' => 'u1', 'username' => 'joe']),
            new LibrariesStore($api),
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            cols: 80,
            rows: 24,
        );
        $app = new App(
            new Config('https://srv'),
            new AuthStore($api, TokenStore::default()),
            $api,
            new LibrariesStore($api),
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            [['route' => Route::Browse, 'screen' => $browse]],
        );

        return $this->startPlaying($app->withAudioFactory($factory));
    }

    public function testToggleMetricsFlipsTheVisibilityFlagAndShowsTheHud(): void
    {
        $app = $this->browsing();
        // Hidden by default: none of the HUD labels appear.
        self::assertStringNotContainsString('Mem', $app->view());

        [$on] = $app->update(new ToggleMetricsMsg());

        self::assertNotSame($app, $on, 'toggling returns a new App copy');
        $view = $on->view();
        self::assertStringContainsString('Mem', $view, 'the HUD shows the memory metric');
        self::assertStringContainsString('Route', $view);
        self::assertStringContainsString('Theme', $view);
        self::assertStringContainsString('Audio', $view);
        // The route name of the top (Browse) frame is shown.
        self::assertStringContainsString('Browse', $view);
    }

    public function testToggleMetricsOffHidesTheHudAgain(): void
    {
        [$on] = $this->browsing()->update(new ToggleMetricsMsg());
        self::assertStringContainsString('Mem', $on->view());

        [$off] = $on->update(new ToggleMetricsMsg());

        self::assertStringNotContainsString('Mem', $off->view(), 'a second toggle hides the HUD');
        self::assertStringNotContainsString('Theme  Nocturne', $off->view());
    }

    public function testThePaletteActionLabelFlipsShowHideAndEmitsToggleMetrics(): void
    {
        // Hidden → the palette offers "Show metrics".
        [$open] = $this->browsing()->update($this->ctrlK());
        $labels = array_map(static fn ($a): string => $a->label, $open->palette()->actions());
        self::assertContains('Show metrics', $labels);
        self::assertNotContains('Hide metrics', $labels);

        // Visible → the palette offers "Hide metrics".
        [$shown] = $this->browsing()->update(new ToggleMetricsMsg());
        [$openShown] = $shown->update($this->ctrlK());
        $shownLabels = array_map(static fn ($a): string => $a->label, $openShown->palette()->actions());
        self::assertContains('Hide metrics', $shownLabels);
        self::assertNotContains('Show metrics', $shownLabels);
    }

    public function testThePaletteMetricsActionDispatchesToggleMetrics(): void
    {
        [$open] = $this->browsing()->update($this->ctrlK());
        // Rank the "Show metrics" action, then select it.
        $typed = $open;
        foreach (['s', 'h', 'o', 'w', ' ', 'm', 'e', 't'] as $rune) {
            [$typed] = $typed->update($rune === ' ' ? new KeyMsg(KeyType::Space) : new KeyMsg(KeyType::Char, $rune));
        }
        self::assertSame('Show metrics', $typed->palette()->selectedAction()?->label);

        [, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(ToggleMetricsMsg::class, $this->runCmd($cmd));
    }

    public function testTheHudShowsTheCurrentRouteAndDepthAfterDrillIn(): void
    {
        // Drill Browse → Library, turn the HUD on: it reflects the live route +
        // stack depth (read off the current App, no counters).
        [$lib] = $this->browsing()->update(new OpenLibraryMsg('lib-a', 'Movies', 'movie'));
        [$on] = $lib->update(new ToggleMetricsMsg());

        $view = $on->view();
        self::assertStringContainsString('Library', $view, 'the HUD route is the top frame');
        self::assertStringContainsString('Depth  2', $view, 'the stack depth is shown');
    }

    public function testTheHudReportsTheLiveTheme(): void
    {
        $on = $this->browsing()->withTheme(Theme::midnight());
        [$on] = $on->update(new ToggleMetricsMsg());

        self::assertStringContainsString('Theme  Midnight', $on->view(), 'the HUD names the active theme');
    }

    public function testTheHudAudioLineIsIdleWhenNothingPlays(): void
    {
        [$on] = $this->browsing()->update(new ToggleMetricsMsg());

        self::assertStringContainsString('Audio  idle', $on->view());
    }

    public function testTheHudAudioLineShowsAPlayingMusicTrack(): void
    {
        // A now-playing music session: the Audio line names the track.
        $playing = $this->startPlaying($this->audioApp());
        [$on] = $playing->update(new ToggleMetricsMsg());

        self::assertStringContainsString('Audio  music: Come Together', $on->view());
    }

    public function testTheHudAudioLineShowsAPausedMusicTrack(): void
    {
        $playing = $this->startPlaying($this->audioApp());
        [$paused] = $playing->update(new ToggleAudioMsg());
        [$on] = $paused->update(new ToggleMetricsMsg());

        self::assertStringContainsString('Audio  music: Come Together (paused)', $on->view());
    }

    public function testTheHudAudioLineShowsAPlayingAudiobook(): void
    {
        // A now-playing audiobook session: the Audio line names the chapter and is
        // tagged "audiobook" (not "music").
        $playing = $this->startAudiobook($this->audiobookApp());
        [$on] = $playing->update(new ToggleMetricsMsg());

        self::assertStringContainsString('Audio  audiobook: Beginnings', $on->view());
    }

    public function testTheMetricsFlagSurvivesNavigation(): void
    {
        // Turn the HUD on, then drill in: the flag is threaded through the push
        // copy, so the HUD is still shown on the new screen.
        [$on] = $this->browsing()->update(new ToggleMetricsMsg());
        self::assertStringContainsString('Mem', $on->view());

        [$lib] = $on->update(new OpenLibraryMsg('lib-a', 'Movies', 'movie'));

        self::assertStringContainsString('Mem', $lib->view(), 'the HUD survives a drill-in (flag threaded through push)');

        // …and back out (threaded through pop).
        [$back] = $lib->update(new NavigateBackMsg());
        self::assertStringContainsString('Mem', $back->view(), 'the HUD survives a pop');
    }

    public function testTheMetricsFlagSurvivesAResize(): void
    {
        [$on] = $this->browsing()->update(new ToggleMetricsMsg());

        [$resized] = $on->update(new WindowSizeMsg(120, 40));

        $view = $resized->view();
        self::assertStringContainsString('Mem', $view, 'the HUD survives a resize (flag threaded through resized())');
        self::assertStringContainsString('Term   120×40', $view, 'the HUD reflects the new terminal size');
    }

    public function testThePaletteStillCompositesWithTheHudOn(): void
    {
        // With the HUD on AND the palette open, both render (compose order intact:
        // the HUD sits under the palette modal).
        [$on] = $this->browsing()->update(new ToggleMetricsMsg());
        [$open] = $on->update($this->ctrlK());

        $view = $open->view();
        self::assertStringContainsString('Mem', $view, 'the HUD is still drawn');
        self::assertStringContainsString('Quit', $view, 'the palette box floats over the HUD');
    }

    public function testAToastStillCompositesWithTheHudOn(): void
    {
        [$on] = $this->browsing()->update(new ToggleMetricsMsg());
        [$toasted] = $on->update(ShowToastMsg::error('HUD_TOAST_PROOF'));

        // The toast is a modal that floats over the top rows; the HUD's lower
        // rows still show beneath it (compose order intact: HUD under, toast over).
        $view = $toasted->view();
        self::assertStringContainsString('Theme  Nocturne', $view, 'the HUD is still drawn under the toast');
        self::assertStringContainsString('HUD_TOAST_PROOF', $view, 'the toast floats over the HUD');
    }

    public function testTheHudCompositesOverThePlayingNowPlayingBar(): void
    {
        // On a real full-height screen the HUD (top-left, rows 0-7) and the
        // now-playing bar (bottom row) occupy different rows — neither clobbers
        // the other.
        [$on] = $this->playingMusicApp()->update(new ToggleMetricsMsg());

        $view = $on->view();
        self::assertStringContainsString('Mem', $view, 'the HUD is in the top-left');
        self::assertStringContainsString('▶ Come Together', $view, 'the now-playing bar still shows on the bottom row');
        self::assertStringContainsString('Audio  music: Come Together', $view, 'the HUD names the playing track');
    }

    /** Drain a (possibly batched) Cmd, running every child (audiobook progress POSTs are fire-and-forget). */
    private function drainBatchCmds(?\Closure $cmd): void
    {
        if ($cmd === null) {
            return;
        }
        $result = $cmd();
        if ($result instanceof BatchMsg) {
            foreach ($result->cmds as $child) {
                $this->runCmd($child);
            }

            return;
        }
        $this->runCmd($cmd);
    }

    // ---- shimmer (loading-skeleton animation) --------------------------

    /**
     * An App whose top screen is a freshly-pushed (still-loading) LibraryScreen.
     * The OpenLibrary transition runs through update()'s chokepoint, so the
     * shimmer tick is already armed on return.
     *
     * @return array{App, ?\Closure} the [app, cmd] from the OpenLibrary update
     */
    private function loadingLibraryApp(): array
    {
        return $this->browsing()->update(new OpenLibraryMsg('lib-a', 'Movies', 'movie'));
    }

    /** Drive a top LibraryScreen to its loaded state by feeding it a range. */
    private function loadLibrary(App $app, int $total = 200): App
    {
        $items = [];
        for ($i = 0; $i < min($total, 24); $i++) {
            $items[$i] = MediaItem::fromArray(['id' => (string) $i, 'name' => "m{$i}", 'type' => 'movie', 'metadata' => ['title' => "M{$i}"]]);
        }
        [$loaded] = $app->update(new MediaRangeLoadedMsg(new MediaRange($items, $total), 0));

        return $loaded;
    }

    /**
     * Run a (possibly batched) Cmd and collect the Msgs its children produce
     * (awaiting any async ones). A bare tick yields no Msg here (its TickRequest
     * is not run).
     *
     * @return list<Msg>
     */
    private function collectBatch(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }
        $result = $cmd();
        if ($result instanceof BatchMsg) {
            // Recurse: a batch's children may themselves be batches (init() batches
            // the range + letter-index fetch, then maybeArmShimmer batches THAT with
            // the tick).
            $msgs = [];
            foreach ($result->cmds as $child) {
                foreach ($this->collectBatch($child) as $m) {
                    $msgs[] = $m;
                }
            }

            return $msgs;
        }
        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? [$msg] : [];
        }

        return $result instanceof Msg ? [$result] : [];
    }

    /** Whether running $cmd ultimately produces a ShimmerTickMsg (through any batch). */
    private function armsShimmerTick(?\Closure $cmd): bool
    {
        if ($cmd === null) {
            return false;
        }

        $result = $cmd();
        if ($result instanceof \SugarCraft\Core\TickRequest) {
            return ($result->produce)() instanceof \Phlix\Console\Msg\ShimmerTickMsg;
        }
        if ($result instanceof BatchMsg) {
            foreach ($result->cmds as $child) {
                if ($this->armsShimmerTick($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function testLandingOnALoadingScreenArmsOneShimmerTick(): void
    {
        [$loading, $cmd] = $this->loadingLibraryApp();

        self::assertTrue($loading->screen()?->isLoading() ?? false, 'the pushed library is loading');
        self::assertTrue($loading->isShimmerTicking(), 'the shimmer tick is armed');
        self::assertTrue($this->armsShimmerTick($cmd), 'the returned Cmd carries a ShimmerTickMsg tick (batched with the load Cmd)');
    }

    public function testTheShimmerArmDoesNotDropTheScreensOwnLoadCmd(): void
    {
        // OpenLibrary's own first-window fetch must survive being batched with the
        // shimmer tick — running the batch yields BOTH a range fetch and a tick.
        [, $cmd] = $this->loadingLibraryApp();

        self::assertInstanceOf(\Closure::class, $cmd, 'a Cmd is returned');
        self::assertTrue($this->armsShimmerTick($cmd), 'the shimmer tick is present');
        $produced = $this->collectBatch($cmd);
        $hasRangeFetch = array_filter($produced, static fn (Msg $m): bool => $m instanceof MediaRangeLoadedMsg);
        self::assertNotEmpty($hasRangeFetch, "the library's own window fetch was not dropped");
    }

    public function testAShimmerTickAdvancesThePhaseAndReArmsWhileLoading(): void
    {
        [$loading] = $this->loadingLibraryApp();
        self::assertSame(0, $loading->shimmerPhase());

        [$ticked, $cmd] = $loading->update(new \Phlix\Console\Msg\ShimmerTickMsg());

        self::assertSame(1, $ticked->shimmerPhase(), 'the phase advanced');
        self::assertTrue($ticked->isShimmerTicking(), 'still ticking while loading');
        self::assertTrue($this->armsShimmerTick($cmd), 'the tick re-arms itself while loading');
    }

    public function testTheShimmerTickStopsOnceTheScreenIsNoLongerLoading(): void
    {
        [$loading] = $this->loadingLibraryApp();
        $loaded = $this->loadLibrary($loading);
        self::assertFalse($loaded->screen()?->isLoading() ?? true, 'the library finished loading');

        [$stopped, $cmd] = $loaded->update(new \Phlix\Console\Msg\ShimmerTickMsg());

        self::assertFalse($stopped->isShimmerTicking(), 'the tick stops when nothing is loading');
        self::assertFalse($this->armsShimmerTick($cmd), 'no re-arm once loaded');
    }

    public function testOnlyOneShimmerChainRuns(): void
    {
        // A second update while already ticking must NOT arm a second chain.
        [$loading] = $this->loadingLibraryApp();
        self::assertTrue($loading->isShimmerTicking());

        // A no-op key on the still-loading screen routes through the chokepoint
        // again — but the in-flight tick already covers the animation.
        [$again, $cmd] = $loading->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertTrue($again->isShimmerTicking(), 'still a single chain');
        self::assertFalse($this->armsShimmerTick($cmd), 'no SECOND shimmer tick is armed');
    }

    public function testANonLoadingTopScreenNeverArmsAShimmerTick(): void
    {
        // Browse is not Loadable; an update on it must not start a shimmer.
        $browse = $this->browsing();
        self::assertFalse($browse->isShimmerTicking());

        [$after, $cmd] = $browse->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertFalse($after->isShimmerTicking(), 'a non-loading screen never arms a shimmer');
        self::assertFalse($this->armsShimmerTick($cmd));
    }

    public function testBaseViewAppliesTheShimmerPhaseToTheLoadingBody(): void
    {
        // Advancing the phase changes the loading body's shimmer (the band moves),
        // proving the App threads its phase into the Shimmering top screen's render.
        [$loading] = $this->loadingLibraryApp();

        $atZero = $loading->view();
        [$advanced] = $loading->update(new \Phlix\Console\Msg\ShimmerTickMsg());
        $atOne = $advanced->view();

        self::assertStringContainsString('░', $atZero, 'the loading body shows the skeleton');
        self::assertNotSame($atZero, $atOne, 'the rendered shimmer reflects the advanced phase');
    }

    public function testStoppingDoesNotLeaveAFreeRunningTick(): void
    {
        // After the screen loads, repeated stray ticks keep returning "stopped"
        // with no re-arm — the animation never free-runs.
        [$loading] = $this->loadingLibraryApp();
        $loaded = $this->loadLibrary($loading);

        [$once, $cmd1] = $loaded->update(new \Phlix\Console\Msg\ShimmerTickMsg());
        [$twice, $cmd2] = $once->update(new \Phlix\Console\Msg\ShimmerTickMsg());

        self::assertFalse($once->isShimmerTicking());
        self::assertFalse($twice->isShimmerTicking());
        self::assertFalse($this->armsShimmerTick($cmd1));
        self::assertFalse($this->armsShimmerTick($cmd2));
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

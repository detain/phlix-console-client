<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\App;
use Phlix\Console\Config\Config;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Config\TokenStore;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\BootResolvedMsg;
use Phlix\Console\Msg\LoginFailedMsg;
use Phlix\Console\Msg\LoginSucceededMsg;
use Phlix\Console\Msg\MediaRangeLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\OpenLibraryMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\SubmitLoginMsg;
use Phlix\Console\Msg\SubmitServerMsg;
use Phlix\Console\Route;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Screen\DetailScreen;
use Phlix\Console\Screen\LibraryScreen;
use Phlix\Console\Screen\LoginScreen;
use Phlix\Console\Screen\ServerScreen;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaRange;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

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

    public function testOpenDetailPushesDetailScreen(): void
    {
        $browse = $this->browsing();

        [$detail, $cmd] = $browse->update(new OpenDetailMsg('m1', 'The Matrix'));

        self::assertSame(Route::Detail, $detail->route());
        self::assertInstanceOf(DetailScreen::class, $detail->screen());
        self::assertSame(2, $detail->stackDepth(), 'detail is pushed onto Browse');
        self::assertInstanceOf(\Closure::class, $cmd, 'the detail fetches its item on push');
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

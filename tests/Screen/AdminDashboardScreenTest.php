<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminDashboardFailedMsg;
use Phlix\Console\Msg\AdminDashboardLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Screen\AdminDashboardScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

final class AdminDashboardScreenTest extends TestCase
{
    private function envelope(mixed $data): array
    {
        return ['success' => true, 'data' => $data, 'count' => is_array($data) ? count($data) : 1];
    }

    /** A transport scripted with all five dashboard envelopes (some populated). */
    private function populatedTransport(): FakeTransport
    {
        return (new FakeTransport())
            ->json(200, $this->envelope([
                ['stream_id' => 'st-1', 'username' => 'joe', 'media_title' => 'Heat', 'progress_percent' => 42.4],
            ]))
            ->json(200, $this->envelope([
                ['user_id' => 'u-1', 'username' => 'joe', 'play_count' => 12],
                ['user_id' => 'u-2', 'username' => 'amy', 'play_count' => 1],
            ]))
            ->json(200, $this->envelope([
                ['media_item_id' => 'm-1', 'title' => 'Heat', 'play_count' => 7],
            ]))
            ->json(200, $this->envelope([
                'movie_bytes' => 1073741824, 'series_bytes' => 0, 'music_bytes' => 1024,
                'photo_bytes' => 512, 'transcode_cache_bytes' => 1048576,
            ]))
            ->json(200, $this->envelope([
                ['id' => 'a-1', 'event_type' => 'login', 'username' => 'joe', 'occurred_at' => '2026-06-26 12:00:00'],
            ]));
    }

    private function emptyTransport(): FakeTransport
    {
        return (new FakeTransport())
            ->json(200, $this->envelope([]))
            ->json(200, $this->envelope([]))
            ->json(200, $this->envelope([]))
            ->json(200, $this->envelope([]))
            ->json(200, $this->envelope([]));
    }

    private function screenWith(FakeTransport $transport): AdminDashboardScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminDashboardScreen(new AdminClient($api), cols: 120, rows: 50);
    }

    /** Drive init → the loaded/failed Msg, then apply it. */
    private function loaded(FakeTransport $transport): AdminDashboardScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertNotNull($msg);

        return $screen->update($msg)[0];
    }

    public function testInitFetchesTheDashboard(): void
    {
        $transport = $this->populatedTransport();
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminDashboardLoadedMsg::class, $msg);
        self::assertSame(5, $transport->requestCount());
    }

    public function testLoadingStateBeforeData(): void
    {
        $screen = $this->screenWith($this->populatedTransport());

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading', $screen->view());
    }

    public function testRendersEveryPanelWithHumanizedStorage(): void
    {
        $loaded = $this->loaded($this->populatedTransport());

        self::assertTrue($loaded->isLoaded());
        self::assertNull($loaded->error());
        self::assertNotNull($loaded->dashboard());

        $view = $loaded->view();
        // Panels.
        self::assertStringContainsString('Now Playing', $view);
        self::assertStringContainsString('Storage', $view);
        self::assertStringContainsString('Top Users', $view);
        self::assertStringContainsString('Top Media', $view);
        self::assertStringContainsString('Recent Activity', $view);
        // Now-playing row (rounded percent).
        self::assertStringContainsString('joe', $view);
        self::assertStringContainsString('Heat', $view);
        self::assertStringContainsString('42%', $view);
        // Humanized storage (1 GiB movies, 1 MiB cache, sub-KiB photos as raw bytes).
        self::assertStringContainsString('1.0 GiB', $view);
        self::assertStringContainsString('1.0 MiB', $view);
        self::assertStringContainsString('512 B', $view);
        // Singular/plural play grammar.
        self::assertStringContainsString('12 plays', $view);
        self::assertStringContainsString('1 play', $view);
        // Activity row.
        self::assertStringContainsString('login', $view);
    }

    public function testEmptyPanelsRenderTheirPlaceholders(): void
    {
        $loaded = $this->loaded($this->emptyTransport());

        $view = $loaded->view();
        self::assertStringContainsString('Nobody is watching', $view);
        self::assertStringContainsString('No watch activity yet', $view);
        self::assertStringContainsString('No play history yet', $view);
        self::assertStringContainsString('No recent activity', $view);
        // Storage still renders (zeroed).
        self::assertStringContainsString('0 B', $view);
    }

    public function testFailedFetchShowsTheErrorAndARetryHint(): void
    {
        $loaded = $this->loaded(
            (new FakeTransport())
                ->json(200, $this->envelope([]))
                ->json(200, $this->envelope([]))
                ->json(200, $this->envelope([]))
                ->json(500, ['error' => 'boom'])
                ->json(200, $this->envelope([])),
        );

        self::assertNotNull($loaded->error());
        $view = $loaded->view();
        self::assertStringContainsString('Could not load the dashboard', $view);
        self::assertStringContainsString('Press r to retry', $view);
    }

    public function testAuthErrorMapsToSessionExpired(): void
    {
        // The first leg is a 401 and there is NO refresh token, so it surfaces as
        // an AuthError → SessionExpiredMsg.
        $api = new ApiClient('https://srv', (new FakeTransport())->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminDashboardScreen(new AdminClient($api), cols: 120, rows: 50);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testRRefetchesAndReturnsToLoading(): void
    {
        $loaded = $this->loaded($this->populatedTransport());

        [$reloading, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertFalse($reloading->isLoaded(), 'r returns to the loading state');
        self::assertInstanceOf(\Closure::class, $cmd, 'r fires a fresh fetch');

        // The fresh fetch resolves to a loaded Msg.
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminDashboardLoadedMsg::class, $msg);
    }

    public function testEscapeAndQGoBack(): void
    {
        $loaded = $this->loaded($this->populatedTransport());

        [, $escCmd] = $loaded->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $loaded->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testFailedMsgIsHandledDirectly(): void
    {
        $screen = $this->screenWith($this->populatedTransport());

        [$failed] = $screen->update(new AdminDashboardFailedMsg('nope'));

        self::assertTrue($failed->isLoaded());
        self::assertSame('nope', $failed->error());
    }

    public function testAnUnhandledKeyIsANoOp(): void
    {
        $loaded = $this->loaded($this->populatedTransport());

        [$next, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertSame($loaded, $next);
        self::assertNull($cmd);
    }

    public function testResizeReflowsTheScreen(): void
    {
        $loaded = $this->loaded($this->populatedTransport());

        [$resized, $cmd] = $loaded->update(new WindowSizeMsg(80, 30));

        self::assertNull($cmd);
        self::assertStringContainsString('Storage', $resized->view());
    }

    public function testCrumbLabelAndImmutability(): void
    {
        $screen = $this->screenWith($this->populatedTransport());
        self::assertSame('Dashboard', $screen->crumbLabel());

        $crumbed = $screen->withCrumbs(['Admin', 'Dashboard']);
        self::assertNotSame($screen, $crumbed);

        $themed = $screen->withTheme(Theme::midnight());
        self::assertNotSame($screen, $themed);
    }

    public function testAnUnhandledMessageIsANoOp(): void
    {
        $screen = $this->screenWith($this->populatedTransport());

        [$next, $cmd] = $screen->update(new class implements Msg {});

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- helpers -------------------------------------------------------

    private function runCmd(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }
        $result = $cmd();
        if ($result instanceof BatchMsg) {
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                if ($msg !== null) {
                    return $msg;
                }
            }

            return null;
        }
        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? $msg : null;
        }

        return $result instanceof Msg ? $result : null;
    }

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null];
        $promise->then(function ($value) use (&$state): void {
            $state['value'] = $value;
            $state['done'] = true;
            Loop::stop();
        });

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        return $state['value'];
    }
}

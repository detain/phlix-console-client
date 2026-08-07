<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Msg\StatsLoadedMsg;
use Phlix\Console\Screen\StatsScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Tests for the new StatsScreen which uses AdminClient::statsOverview()
 * instead of the old library-based approach.
 */
final class StatsScreenTest extends TestCase
{
    private function screenWith(FakeTransport $transport): StatsScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new StatsScreen(new AdminClient($api));
    }

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

    private function await(PromiseInterface $promise, float $timeout = 5.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $timer = null;
        $settle = static function () use (&$timer): void {
            if ($timer !== null) {
                Loop::cancelTimer($timer);
                $timer = null;
            }
            Loop::stop();
        };
        $promise->then(
            function ($v) use (&$state, $settle): void {
                $state['value'] = $v;
                $state['done'] = true;
                $settle();
            },
            function ($e) use (&$state, $settle): void {
                $state['error'] = $e;
                $state['done'] = true;
                $settle();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
        }

        if (!$state['done']) {
            throw new \RuntimeException('cmd did not settle in time');
        }
        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }

    public function testInitFetchesStatsOverview(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['data' => [['date' => '2026-01-01', 'play_count' => 5, 'total_duration' => 100, 'completed_count' => 2]]])
            ->json(200, ['data' => [['id' => '1', 'recorded_at' => '2026-01-01', 'library_id' => 'lib1', 'media_type' => 'video', 'item_count' => 10, 'total_bytes' => 1024, 'transcode_cache_bytes' => 256]]])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(StatsLoadedMsg::class, $msg);
        self::assertIsArray($msg->stats['playback']);
        self::assertIsArray($msg->stats['storage']);
    }

    public function testLoadingViewRendersBeforeDataArrives(): void
    {
        $transport = (new FakeTransport())->pending();
        $screen = $this->screenWith($transport);
        $screen->init()();

        self::assertStringContainsString('Loading stats', $screen->view());
    }

    public function testLoadedViewRendersStatsData(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['data' => [['date' => '2026-01-01', 'play_count' => 3, 'total_duration' => 100, 'completed_count' => 1]]])
            ->json(200, ['data' => [['id' => '1', 'recorded_at' => '2026-01-01', 'library_id' => 'lib1', 'media_type' => 'video', 'item_count' => 5, 'total_bytes' => 500, 'transcode_cache_bytes' => 0]]])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(StatsLoadedMsg::class, $msg);

        [$updated] = $screen->update($msg);
        $view = $updated->view();

        self::assertStringContainsString('Server Statistics', $view);
        self::assertStringContainsString('Playback:', $view);
        self::assertStringContainsString('Storage:', $view);
    }

    public function testAuthErrorProducesSessionExpiredMsg(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'unauthorized']);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testNetworkErrorProducesToastError(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('connection refused'));
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(ShowToastMsg::class, $msg);
        self::assertStringContainsString('connection refused', $msg->message);
    }

    public function testEscapeKeyReturnsToPreviousScreen(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['data' => [['date' => '2026-01-01', 'play_count' => 1, 'total_duration' => 10, 'completed_count' => 0]]])
            ->json(200, ['data' => [['id' => '1', 'recorded_at' => '2026-01-01', 'library_id' => 'lib1', 'media_type' => 'video', 'item_count' => 1, 'total_bytes' => 100, 'transcode_cache_bytes' => 0]]])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(StatsLoadedMsg::class, $msg);
        $screen = $screen->update($msg)[0];

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertSame($screen, $same);
        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    public function testRKeyRefreshesStats(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['data' => [['date' => '2026-01-01', 'play_count' => 1, 'total_duration' => 10, 'completed_count' => 0]]])
            ->json(200, ['data' => [['id' => '1', 'recorded_at' => '2026-01-01', 'library_id' => 'lib1', 'media_type' => 'video', 'item_count' => 1, 'total_bytes' => 100, 'transcode_cache_bytes' => 0]]])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        $screen = $screen->update($msg)[0];

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertSame($screen, $same);
        self::assertNotNull($cmd);
    }

    public function testEmptyDataHandlesGracefully(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['data' => []])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(StatsLoadedMsg::class, $msg);

        [$updated] = $screen->update($msg);
        $view = $updated->view();

        self::assertStringContainsString('Server Statistics', $view);
        self::assertStringContainsString('Playback:', $view);
        self::assertStringContainsString('Storage:', $view);
    }

    public function testUnhandledKeyIsNoOp(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['data' => [['date' => '2026-01-01', 'play_count' => 1, 'total_duration' => 10, 'completed_count' => 0]]])
            ->json(200, ['data' => [['id' => '1', 'recorded_at' => '2026-01-01', 'library_id' => 'lib1', 'media_type' => 'video', 'item_count' => 1, 'total_bytes' => 100, 'transcode_cache_bytes' => 0]]])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        $screen = $screen->update($msg)[0];

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testInitMsgTriggersFetch(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['data' => [['date' => '2026-01-01', 'play_count' => 2, 'total_duration' => 20, 'completed_count' => 1]]])
            ->json(200, ['data' => [['id' => '1', 'recorded_at' => '2026-01-01', 'library_id' => 'lib1', 'media_type' => 'video', 'item_count' => 2, 'total_bytes' => 256, 'transcode_cache_bytes' => 0]]])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
        $screen = $this->screenWith($transport);

        [$same, $cmd] = $screen->update(new InitMsg());
        self::assertSame($screen, $same);
        self::assertNotNull($cmd);
    }
}

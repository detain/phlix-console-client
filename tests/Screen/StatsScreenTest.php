<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\StatsFailedMsg;
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
use SugarCraft\Core\Msg\WindowSizeMsg;

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
            ->json(200, ['data' => [['play_count' => 3, 'completed_count' => 1]]])
            ->json(200, ['data' => [['item_count' => 5, 'total_bytes' => 500]]])
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

    public function testNetworkErrorRendersTheErrorInFrame(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('connection refused'));
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(StatsFailedMsg::class, $msg);
        // M11 cure flip retained: the transport failure is wrapped to NetworkError whose
        // message is now the curated sentence (source fix) — the raw
        // 'connection refused' tail reaches the log, not the screen.
        self::assertStringContainsString('Could not reach the server', $msg->message);
        self::assertStringNotContainsString('connection refused', $msg->message);

        // [CU-021: reviews console__enduser #3, console__uxdesign #9] The failure law
        // rotated from "toast that vanishes over a spinner that never settles" to
        // error-in-frame (the FederationShares screen shape): the reason and the retry
        // affordance render, and the Loading body is gone.
        [$updated] = $screen->update($msg);
        $view = $updated->view();

        self::assertStringContainsString('Could not reach the server', $view);
        self::assertStringContainsString('Press r to retry.', $view);
        self::assertStringNotContainsString('Loading stats', $view);
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

    /** A transport scripted with four empty success responses (all stats legs). */
    private function emptyStatsTransport(): FakeTransport
    {
        return (new FakeTransport())
            ->json(200, ['data' => []])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
    }

    /**
     * [CU-021: review console__uxdesign #9] The old view() returned a raw string
     * with no frame, no brand, and no hint line. Proof of the shared chrome: the
     * brand + the fitted hint render (and the too-small notice does not).
     */
    public function testTheViewCarriesTheSharedChromeAndHintLine(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['data' => [['play_count' => 3, 'completed_count' => 1]]])
            ->json(200, ['data' => [['item_count' => 5, 'total_bytes' => 500]]])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(StatsLoadedMsg::class, $msg);
        [$updated] = $screen->update($msg);
        $view = $updated->view();

        self::assertStringContainsString(' Phlix ', $view, 'the frame brand renders — the screen is chrome-framed now');
        self::assertStringContainsString(
            'Esc  back      r  refresh',
            $view,
            'the bound keys are advertised by the status line'
        );
        self::assertStringNotContainsString('Terminal too small.', $view);
    }

    /**
     * [CU-021: review console__uxdesign #9] Storage snapshot rows used to print
     * raw integers (in fact they printed 0: the old body read map keys the list
     * payload never carries). They now sum the rows and render human sizes
     * through the shared Bytes formatter.
     */
    public function testStorageCountsRenderHumanReadable(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['data' => []])
            ->json(200, ['data' => [[
                'item_count' => 7,
                'total_bytes' => 12345678901,
                'transcode_cache_bytes' => 1048576,
            ]]])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(StatsLoadedMsg::class, $msg);
        [$updated] = $screen->update($msg);
        $view = $updated->view();

        self::assertStringContainsString('11.5 GiB', $view);
        self::assertStringContainsString('1.0 MiB', $view, 'the transcode cache row is humanized too');
        self::assertStringNotContainsString('12345678901', $view, 'raw byte integers are gone');
    }

    /**
     * Defense-in-depth for a mid-session demotion [CU-021: review
     * console__enduser #3]: the palette gate is the front door, but a 403 from
     * the admin stats API must say the account needs admin rights — in the
     * frame, not in a toast.
     */
    public function testForbiddenFetchSaysTheAccountNeedsAdminRights(): void
    {
        $transport = (new FakeTransport())->json(403, ['error' => 'Forbidden']);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(StatsFailedMsg::class, $msg);
        self::assertStringContainsString('need an admin account', $msg->message);

        [$updated] = $screen->update($msg);
        self::assertStringContainsString('need an admin account', $updated->view());
    }

    /**
     * The hardcoded 80×24 era is over [CU-021: review console__uxdesign #9]:
     * WindowSizeMsg refits the frame like every chrome screen.
     */
    public function testWindowSizeMsgRefitsTheFrame(): void
    {
        $screen = $this->screenWith($this->emptyStatsTransport());

        [$resized] = $screen->update(new WindowSizeMsg(40, 16));

        self::assertNotSame($screen, $resized, 'the resize hands back a refitted copy');
        $view = $resized->view();
        self::assertStringContainsString('Server Statistics', $view);
        self::assertStringNotContainsString('Terminal too small.', $view);

        // Below Chrome's honest floor the frame says so instead of squeezing. The
        // notice leaf CLIPS at 12 cols, so assert the surviving prefix.
        [$tiny] = $screen->update(new WindowSizeMsg(12, 6));
        self::assertStringContainsString('Resize', $tiny->view(), 'the too-small notice replaces the squeezed body');
    }

    /**
     * Retry clears the error body (the forever-Loading/forever-toast class):
     * a successful re-fetch after a failure renders stats, not the stale reason.
     */
    public function testSuccessfulRetryClearsTheError(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('connection refused'))
            ->json(200, ['data' => [['play_count' => 1]]])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []])
            ->json(200, ['data' => []]);
        $screen = $this->screenWith($transport);

        $failed = $this->runCmd($screen->init());
        self::assertInstanceOf(StatsFailedMsg::class, $failed);
        [$errored] = $screen->update($failed);
        self::assertStringContainsString('Press r to retry.', $errored->view());

        $loaded = $this->runCmd($errored->update(new KeyMsg(KeyType::Char, 'r'))[1]);
        self::assertInstanceOf(StatsLoadedMsg::class, $loaded);
        [$recovered] = $errored->update($loaded);
        $view = $recovered->view();

        self::assertStringNotContainsString('Press r to retry.', $view, 'the error body is gone once data lands');
        self::assertStringNotContainsString('Could not reach the server', $view);
        self::assertStringContainsString('Playback:', $view);
    }
}

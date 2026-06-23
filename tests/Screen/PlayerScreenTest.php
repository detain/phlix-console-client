<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\PlayerPrepareFailedMsg;
use Phlix\Console\Msg\PlayerReadyMsg;
use Phlix\Console\Screen\PlayerScreen;
use Phlix\Console\Tests\Reel\FakePlayerDecoder;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Msg\TickMsg as ReelTickMsg;
use SugarCraft\Reel\Player;

final class PlayerScreenTest extends TestCase
{
    private const STREAM = 'https://srv/media/m1/stream?exp=1&sig=abc';

    private function item(?string $streamUrl = self::STREAM): MediaItem
    {
        return MediaItem::fromArray([
            'id' => 'm1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'stream_url' => $streamUrl,
        ]);
    }

    /** @return list<RgbFrame> small RGB frames; content is irrelevant to these tests */
    private function frames(int $count = 60): array
    {
        $frames = [];
        for ($i = 0; $i < $count; $i++) {
            $frames[] = new RgbFrame(str_repeat("\x20\x30\x40", 64), 8, 8);
        }

        return $frames;
    }

    /**
     * Build a screen whose factory produces a fake-decoder-backed Player; expose
     * the decoder (to assert teardown) and the URLs the factory was handed.
     *
     * @param list<string> $captured
     * @return array{PlayerScreen, FakePlayerDecoder}
     */
    private function screen(
        ?string $streamUrl = self::STREAM,
        string $base = 'https://srv',
        array &$captured = [],
        int $cols = 80,
        int $rows = 24,
    ): array {
        $decoder = new FakePlayerDecoder($this->frames());
        $factory = function (string $url, int $c, int $r) use ($decoder, &$captured): Player {
            $captured[] = $url;

            // totalFrames 2400 @ 24fps = a 100s clip, so ±10s seeks aren't clamped.
            return Player::openForTest($decoder, fps: 24.0, totalFrames: 2400, cellsW: $c, cellsH: $r, videoPath: '/fake', paused: true);
        };
        $screen = new PlayerScreen($this->item($streamUrl), $base, $factory, cols: $cols, rows: $rows);

        return [$screen, $decoder];
    }

    /** Init → run the build Cmd → feed the resulting Msg → the ready (auto-playing) screen. */
    private function ready(PlayerScreen $screen): PlayerScreen
    {
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(PlayerReadyMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    // ---- build / direct-play -------------------------------------------

    public function testInitBuildsThePlayerAndAutoPlays(): void
    {
        [$screen] = $this->screen();

        $ready = $this->ready($screen);

        self::assertTrue($ready->isReady());
        self::assertTrue($ready->isPlaying(), 'playback auto-starts on ready');
        self::assertInstanceOf(Player::class, $ready->player());
    }

    public function testAbsoluteSignedUrlIsFedToFfmpegVerbatim(): void
    {
        $captured = [];
        [$screen] = $this->screen(captured: $captured);

        $this->ready($screen);

        self::assertSame([self::STREAM], $captured, 'an already-signed absolute URL is used as-is');
    }

    public function testRelativeStreamUrlIsResolvedAgainstTheServerBase(): void
    {
        $captured = [];
        [$screen] = $this->screen('/media/m1/stream?sig=x', 'https://srv', $captured);

        $this->ready($screen);

        self::assertSame(['https://srv/media/m1/stream?sig=x'], $captured);
    }

    public function testMissingStreamUrlShowsAPrepareFailure(): void
    {
        [$screen] = $this->screen(streamUrl: null);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(PlayerPrepareFailedMsg::class, $msg);
        [$failed] = $screen->update($msg);
        self::assertFalse($failed->isReady());
        self::assertStringContainsString('no playable source', (string) $failed->error());
        self::assertStringContainsString('no playable source', $failed->view());
    }

    public function testFactoryFailureBecomesAPrepareFailure(): void
    {
        $factory = static fn (string $url, int $c, int $r): Player => throw new \RuntimeException('ffmpeg missing');
        $screen = new PlayerScreen($this->item(), 'https://srv', $factory, cols: 80, rows: 24);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(PlayerPrepareFailedMsg::class, $msg);
        self::assertStringContainsString('ffmpeg missing', $msg->reason);
    }

    public function testPreparingViewBeforeReady(): void
    {
        [$screen] = $this->screen();

        $view = $screen->view();
        self::assertStringContainsString('Preparing', $view);
        self::assertStringContainsString('The Matrix', $view);
    }

    // ---- transport -----------------------------------------------------

    public function testSpaceTogglesPause(): void
    {
        $ready = $this->ready($this->screen()[0]);
        self::assertTrue($ready->isPlaying());

        [$paused] = $ready->update(new KeyMsg(KeyType::Space));

        self::assertFalse($paused->isPlaying(), 'Space pauses the inner player');
    }

    public function testRightArrowSeeksForwardTenSeconds(): void
    {
        $ready = $this->ready($this->screen()[0]);
        self::assertSame(0.0, $ready->position());

        [$seeked] = $ready->update(new KeyMsg(KeyType::Right));

        self::assertSame(10.0, $seeked->position(), '→ seeks +10s');
    }

    public function testLeftArrowClampsAtZero(): void
    {
        $ready = $this->ready($this->screen()[0]);

        [$seeked] = $ready->update(new KeyMsg(KeyType::Left));

        self::assertSame(0.0, $seeked->position(), '← at the start clamps to 0');
    }

    public function testTickPumpsTheInnerPlayer(): void
    {
        $ready = $this->ready($this->screen()[0]);

        [$next, $cmd] = $ready->update(new ReelTickMsg());

        self::assertInstanceOf(PlayerScreen::class, $next);
        self::assertNotNull($cmd, 'a playing tick re-arms the next tick (the frame pump)');
    }

    public function testFullscreenTogglesTheTransportChrome(): void
    {
        $ready = $this->ready($this->screen()[0]);
        self::assertStringContainsString('±10s', $ready->view(), 'transport shown by default');

        [$hidden] = $ready->update(new KeyMsg(KeyType::Char, 'f'));

        self::assertTrue($hidden->isChromeHidden());
        self::assertStringNotContainsString('±10s', $hidden->view(), 'transport hidden in fullscreen');
    }

    public function testTransportLineShowsTitleAndClock(): void
    {
        $view = $this->ready($this->screen()[0])->view();

        self::assertStringContainsString('The Matrix', $view);
        self::assertStringContainsString('0:00', $view, 'position / duration clock');
    }

    public function testResizeReflowsAndStillRenders(): void
    {
        $ready = $this->ready($this->screen()[0]);

        [$resized] = $ready->update(new WindowSizeMsg(120, 40));

        self::assertIsString($resized->view());
    }

    // ---- teardown ------------------------------------------------------

    public function testEscapeTearsDownAndNavigatesBack(): void
    {
        [$screen, $decoder] = $this->screen();
        $ready = $this->ready($screen);

        [, $cmd] = $ready->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
        self::assertTrue($decoder->isClosed(), 'leaving the player stops the decoder (no leaked ffmpeg)');
    }

    public function testQuitKeyAlsoTearsDownAndNavigatesBack(): void
    {
        [$screen, $decoder] = $this->screen();
        $ready = $this->ready($screen);

        [, $cmd] = $ready->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
        self::assertTrue($decoder->isClosed());
    }

    public function testTeardownIsIdempotent(): void
    {
        [$screen, $decoder] = $this->screen();
        $ready = $this->ready($screen);

        $ready->teardown();
        $ready->teardown(); // must not throw

        self::assertTrue($decoder->isClosed());
    }

    public function testUnhandledMessageIsANoOp(): void
    {
        $ready = $this->ready($this->screen()[0]);

        // A message the player screen doesn't handle (it only *sends* NavigateBack).
        [$same, $cmd] = $ready->update(new NavigateBackMsg());

        self::assertSame($ready, $same);
        self::assertNull($cmd);
    }

    public function testProductionFactoryReturnsAClosure(): void
    {
        // The closure body (Player::open → real ffmpeg) is exercised by the live
        // test below; here we only assert the factory's shape.
        self::assertInstanceOf(\Closure::class, PlayerScreen::productionFactory());
    }

    /**
     * Live end-to-end with real ffmpeg (watchdog-guarded, skipped when absent):
     * an empty base + a local absolute path makes streamUrl() yield the path
     * verbatim, so the production factory opens the real clip — proving the whole
     * PlayerScreen wiring drives a real sugar-reel Player (ready, playing, probed).
     */
    public function testLivePlaybackOfALocalClipViaTheProductionFactory(): void
    {
        if (!\SugarCraft\Reel\Source\Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/phlix-player-' . getmypid() . '.mp4';
        $wd = proc_open(['sh', '-c', 'sleep 20; pkill -9 -f phlix-player'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $wdPipes);

        try {
            $gen = proc_open(
                ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc=duration=2:size=128x96:rate=12', '-y', $clip],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $genPipes,
            );
            self::assertIsResource($gen);
            foreach ($genPipes as $p) {
                if (is_resource($p)) {
                    fclose($p);
                }
            }
            proc_close($gen);
            self::assertFileExists($clip);

            $item = MediaItem::fromArray(['id' => 'm1', 'name' => 'Clip', 'type' => 'movie', 'stream_url' => $clip]);
            $screen = new PlayerScreen($item, '', PlayerScreen::productionFactory(), cols: 60, rows: 20);

            $msg = $this->runCmd($screen->init());
            self::assertInstanceOf(PlayerReadyMsg::class, $msg);
            [$ready] = $screen->update($msg);

            self::assertTrue($ready->isReady());
            self::assertTrue($ready->isPlaying(), 'a real clip auto-plays');
            self::assertGreaterThan(0.0, $ready->player()?->duration() ?? 0.0, 'ffprobe read the clip duration');
            self::assertNotSame('', $ready->view(), 'the player renders');

            $ready->teardown();
        } finally {
            if (is_resource($wd)) {
                proc_terminate($wd);
                proc_close($wd);
            }
            if (is_file($clip)) {
                @unlink($clip);
            }
        }
    }

    // ---- before-ready guards + ended-seek edge -------------------------

    public function testTransportKeysBeforeReadyAreIgnored(): void
    {
        [$screen] = $this->screen(); // not readied — inner is null

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Space));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testTickBeforeReadyIsIgnored(): void
    {
        [$screen] = $this->screen();

        [$same, $cmd] = $screen->update(new ReelTickMsg());

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testResizeBeforeReadyStillRenders(): void
    {
        [$screen] = $this->screen();

        [$resized] = $screen->update(new WindowSizeMsg(100, 30));

        self::assertStringContainsString('Preparing', $resized->view());
    }

    public function testSeekingOutOfTheEndedStateRearmsTheTickPump(): void
    {
        [$screen] = $this->screen();
        // Drive a tiny inner player to the ended state: an empty decoder runs out
        // on the first tick (non-loop → ended, ticking stops).
        $emptyInner = Player::openForTest(new FakePlayerDecoder([]), fps: 24.0, totalFrames: 0, videoPath: '/fake', paused: false);
        [$ended] = $emptyInner->update(new ReelTickMsg());
        self::assertTrue($ended->ended);

        [$ready] = $screen->update(new PlayerReadyMsg($ended));
        [, $cmd] = $ready->update(new KeyMsg(KeyType::Right));

        self::assertNotNull($cmd, 'seeking out of ended must re-arm the frame pump (it had stopped ticking)');
    }

    // ---- harness (mirrors DetailScreenTest) ----------------------------

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
}

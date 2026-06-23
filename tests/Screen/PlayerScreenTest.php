<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\PlaybackMarkersLoadedMsg;
use Phlix\Console\Msg\PlayerPrepareFailedMsg;
use Phlix\Console\Msg\PlayerReadyMsg;
use Phlix\Console\Msg\PlayNextMsg;
use Phlix\Console\Msg\ProgressTickMsg;
use Phlix\Console\Msg\ResumeInfoMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\TranscodePollMsg;
use Phlix\Console\Msg\TranscodeStartedMsg;
use Phlix\Console\Msg\TranscodeStatusMsg;
use Phlix\Console\Msg\UpNextTickMsg;
use Phlix\Console\Screen\PlayerScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Tests\Reel\FakePlayerDecoder;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
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

    /** The `/playback-info` shape (intro 5–30s, outro 90–100s, two chapters at 0/50). */
    private function markersResponse(array $overrides = []): array
    {
        return array_merge([
            'item_id' => 'm1',
            'intro_marker' => ['start_seconds' => 5.0, 'end_seconds' => 30.0],
            'outro_marker' => ['start_seconds' => 90.0, 'end_seconds' => 100.0],
            'chapters' => [
                ['start_seconds' => 0.0, 'end_seconds' => 50.0, 'title' => 'Part 1'],
                ['start_seconds' => 50.0, 'end_seconds' => 100.0, 'title' => 'Part 2'],
            ],
            'skip_button_spec' => ['skip_intro_start' => 5.0, 'skip_intro_end' => 30.0, 'skip_outro_start' => 90.0, 'skip_outro_end' => 100.0],
        ], $overrides);
    }

    /** A continue-watching response (default: no items → nothing to resume). */
    private function continueWatching(array $items = []): array
    {
        return ['items' => $items];
    }

    /** A single continue-watching row for $id at $positionTicks / $durationTicks. */
    private function watchedRow(string $id, int $positionTicks, int $durationTicks): array
    {
        return [
            'media_item_id' => $id,
            'name' => 'The Matrix',
            'type' => 'movie',
            'position_ticks' => $positionTicks,
            'duration_ticks' => $durationTicks,
            'playback_status' => 'in_progress',
        ];
    }

    /**
     * Build a screen whose factory produces a fake-decoder-backed Player; expose
     * the decoder (to assert teardown) and the URLs the factory was handed. The
     * markers transport defaults to the standard `/playback-info` response.
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
        ?FakeTransport $transport = null,
    ): array {
        $decoder = new FakePlayerDecoder($this->frames());
        $factory = function (string $url, int $c, int $r) use ($decoder, &$captured): Player {
            $captured[] = $url;

            // totalFrames 2400 @ 24fps = a 100s clip, so ±10s seeks aren't clamped.
            return Player::openForTest($decoder, fps: 24.0, totalFrames: 2400, cellsW: $c, cellsH: $r, videoPath: '/fake', paused: true);
        };
        $transport ??= (new FakeTransport())->json(200, $this->markersResponse());
        $api = new ApiClient($base, $transport);
        $screen = new PlayerScreen($this->item($streamUrl), $base, $api, $factory, cols: $cols, rows: $rows);

        return [$screen, $decoder];
    }

    /**
     * Init (build player + fetch markers concurrently) → feed every resulting
     * Msg → the ready (auto-playing, markers-loaded) screen.
     */
    private function ready(PlayerScreen $screen): PlayerScreen
    {
        $cur = $screen;
        foreach ($this->runBatch($screen->init()) as $msg) {
            [$cur] = $cur->update($msg);
        }
        self::assertTrue($cur->isReady());

        return $cur;
    }

    /**
     * Drive init → onReady (auto-play + open session) → SessionStarted, so the
     * returned screen has a live session. The transport must queue, in order:
     * markers, the session, then any progress responses the test triggers.
     */
    private function readyWithSession(FakeTransport $transport): PlayerScreen
    {
        [$screen] = $this->screen(transport: $transport);
        $cur = $screen;
        foreach ($this->runBatch($screen->init()) as $msg) {
            [$cur, $cmd] = $cur->update($msg);
            // PlayerReady's Cmd is the auto-play + createSession batch — run it
            // and feed the SessionStarted it yields.
            foreach ($this->runBatch($cmd) as $sub) {
                [$cur] = $cur->update($sub);
            }
        }
        self::assertTrue($cur->isReady());

        return $cur;
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

    public function testMissingStreamUrlFallsBackToTranscode(): void
    {
        [$screen] = $this->screen(streamUrl: null);

        $msg = $this->firstOfType($this->runBatch($screen->init()), PlayerPrepareFailedMsg::class);
        self::assertInstanceOf(PlayerPrepareFailedMsg::class, $msg);

        // A first build failure no longer errors outright — it tries a transcode.
        [$next, $cmd] = $screen->update($msg);
        self::assertTrue($next->isTranscoding(), 'a build failure starts the server transcode');
        self::assertInstanceOf(\Closure::class, $cmd);
    }

    public function testFactoryFailureBecomesAPrepareFailure(): void
    {
        $factory = static fn (string $url, int $c, int $r): Player => throw new \RuntimeException('ffmpeg missing');
        $api = new ApiClient('https://srv', (new FakeTransport())->json(200, $this->markersResponse()));
        $screen = new PlayerScreen($this->item(), 'https://srv', $api, $factory, cols: 80, rows: 24);

        $msg = $this->firstOfType($this->runBatch($screen->init()), PlayerPrepareFailedMsg::class);

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
            $api = new ApiClient('https://srv', (new FakeTransport())->json(200, $this->markersResponse()));
            $screen = new PlayerScreen($item, '', $api, PlayerScreen::productionFactory(), cols: 60, rows: 20);

            $msg = $this->firstOfType($this->runBatch($screen->init()), PlayerReadyMsg::class);
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

    // ---- markers / scrubber / skip -------------------------------------

    public function testReadyLoadsTheMarkers(): void
    {
        $ready = $this->ready($this->screen()[0]);

        $markers = $ready->markers();
        self::assertNotNull($markers);
        self::assertNotNull($markers->intro);
        self::assertCount(2, $markers->chapters);
    }

    public function testScrubberAndClockRenderInTheView(): void
    {
        $view = $this->ready($this->screen()[0])->view();

        self::assertStringContainsString('0:00', $view, 'position clock');
        self::assertStringContainsString('1:40', $view, '2400 frames @ 24fps = 100s = 1:40');
        self::assertStringContainsString('░', $view, 'the progress bar renders');
        self::assertStringContainsString('│', $view, 'a chapter tick renders');
    }

    public function testSkipIntroSeeksToTheIntroEnd(): void
    {
        $ready = $this->ready($this->screen()[0]);

        // → moves to 10s, inside the intro window [5, 30).
        [$inIntro] = $ready->update(new KeyMsg(KeyType::Right));
        self::assertSame(10.0, $inIntro->position());
        self::assertStringContainsString('Skip Intro', $inIntro->view(), 'the skip prompt shows in-range');

        [$skipped] = $inIntro->update(new KeyMsg(KeyType::Char, 's'));

        self::assertSame(30.0, $skipped->position(), 's seeks to the intro end');
    }

    public function testSkipWithNoActiveMarkerIsANoOp(): void
    {
        // Position 0 is outside the intro [5, 30) and outro [90, 100) windows.
        $ready = $this->ready($this->screen()[0]);

        [$same, $cmd] = $ready->update(new KeyMsg(KeyType::Char, 's'));

        self::assertSame($ready, $same);
        self::assertNull($cmd);
    }

    public function testMarkersAuthErrorBecomesSessionExpired(): void
    {
        [$screen] = $this->screen(transport: (new FakeTransport())->json(401, ['error' => 'unauthorized']));

        $msgs = $this->runBatch($screen->init());

        self::assertNotNull($this->firstOfType($msgs, SessionExpiredMsg::class), 'a markers 401 surfaces as session expiry');
    }

    public function testMarkersFetchFailureLeavesAPlainScrubber(): void
    {
        [$screen] = $this->screen(transport: (new FakeTransport())->fail(new \RuntimeException('boom')));

        $ready = $this->ready($screen);

        self::assertNull($ready->markers(), 'a non-auth markers failure is swallowed');
        self::assertStringContainsString('░', $ready->view(), 'the scrubber still renders without ticks');

        // s does nothing when there are no markers to skip.
        [$same, $cmd] = $ready->update(new KeyMsg(KeyType::Char, 's'));
        self::assertSame($ready, $same);
        self::assertNull($cmd);
    }

    // ---- transcode fallback --------------------------------------------

    /**
     * A screen whose factory FAILS to direct-play (throws) but succeeds on an
     * HLS master URL — so the transcode fallback can be exercised.
     *
     * @return array{PlayerScreen, FakePlayerDecoder}
     */
    private function transcodeScreen(FakeTransport $transport): array
    {
        $decoder = new FakePlayerDecoder($this->frames());
        $factory = function (string $url, int $c, int $r) use ($decoder): Player {
            if (str_contains($url, 'master.m3u8')) {
                return Player::openForTest($decoder, fps: 24.0, totalFrames: 2400, cellsW: $c, cellsH: $r, videoPath: '/fake', paused: true);
            }
            throw new \RuntimeException('cannot direct-play this container');
        };
        $api = new ApiClient('https://srv', $transport);
        $screen = new PlayerScreen($this->item(), 'https://srv', $api, $factory, cols: 80, rows: 24);

        return [$screen, $decoder];
    }

    public function testDirectPlayFailurePollsTheTranscodeThenPlaysTheHls(): void
    {
        $master = '/hls/j1/master.m3u8';
        $transport = (new FakeTransport())
            ->json(200, ['job_id' => 'j1', 'master_url' => $master, 'status' => 'running']) // start: not ready
            ->json(200, ['job_id' => 'j1', 'status' => 'running', 'playlist_ready' => false, 'progress' => 20, 'master_url' => $master])
            ->json(200, ['job_id' => 'j1', 'status' => 'running', 'playlist_ready' => true, 'progress' => 60, 'master_url' => $master]);
        [$screen] = $this->transcodeScreen($transport);

        // Direct-play failed → transcode begins.
        [$s, $cmd] = $screen->update(new PlayerPrepareFailedMsg('cannot direct-play'));
        self::assertTrue($s->isTranscoding());
        self::assertStringContainsString('transcoding', $s->view());

        $started = $this->runCmd($cmd);
        self::assertInstanceOf(TranscodeStartedMsg::class, $started);
        [$s] = $s->update($started); // not playable → schedules a poll tick
        self::assertSame('j1', $s->transcodeJob());

        // Poll 1 (running, not ready) — feed the tick + run the status fetch.
        [$s, $statusCmd] = $s->update(new TranscodePollMsg());
        [$s] = $s->update($this->runCmd($statusCmd));
        self::assertTrue($s->isTranscoding(), 'still transcoding');

        // Poll 2 (playlist ready) → build from the HLS master, which succeeds.
        [$s, $statusCmd2] = $s->update(new TranscodePollMsg());
        [$s, $buildCmd] = $s->update($this->runCmd($statusCmd2));
        $ready = $this->runCmd($buildCmd);
        self::assertInstanceOf(PlayerReadyMsg::class, $ready);
        [$playing] = $s->update($ready);

        self::assertTrue($playing->isReady());
        self::assertTrue($playing->isPlaying(), 'the HLS player auto-plays');
    }

    public function testTranscodeStartAlreadyPlayableBuildsImmediately(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['job_id' => 'j2', 'master_url' => '/hls/j2/master.m3u8', 'status' => 'completed']);
        [$screen] = $this->transcodeScreen($transport);

        [$s, $cmd] = $screen->update(new PlayerPrepareFailedMsg('x'));
        [$s, $buildCmd] = $s->update($this->runCmd($cmd)); // TranscodeStartedMsg, playable → build
        $ready = $this->runCmd($buildCmd);

        self::assertInstanceOf(PlayerReadyMsg::class, $ready);
    }

    public function testTranscodeFailedStatusShowsError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['job_id' => 'j3', 'master_url' => '/hls/j3/master.m3u8', 'status' => 'running']);
        [$screen] = $this->transcodeScreen($transport);

        [$s, $cmd] = $screen->update(new PlayerPrepareFailedMsg('x'));
        [$s] = $s->update($this->runCmd($cmd)); // started, polling

        // A failed status → error.
        [$failed] = $s->update(new TranscodeStatusMsg(\Phlix\Console\Api\Dto\TranscodeJob::fromArray([
            'job_id' => 'j3', 'status' => 'failed',
        ])));

        self::assertFalse($failed->isReady());
        self::assertStringContainsString('could not be prepared', (string) $failed->error());
        self::assertStringContainsString('could not be prepared', $failed->view());
    }

    public function testTranscodeStartFailureShowsErrorOnTheSecondPrepareFailure(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('503 busy')); // start fails
        [$screen] = $this->transcodeScreen($transport);

        [$s, $cmd] = $screen->update(new PlayerPrepareFailedMsg('direct failed'));
        $secondFail = $this->runCmd($cmd); // start → PlayerPrepareFailedMsg again
        self::assertInstanceOf(PlayerPrepareFailedMsg::class, $secondFail);

        // The 2nd prepare-failure (transcode already tried) errors out.
        [$failed] = $s->update($secondFail);
        self::assertFalse($failed->isReady());
        self::assertNotNull($failed->error());
    }

    // ---- captions ------------------------------------------------------

    /**
     * A ready screen with captions toggled on and a track loaded.
     *
     * @param list<array<string,mixed>> $tracks
     */
    private function readyWithCaptions(array $tracks, string $vtt): PlayerScreen
    {
        $transport = (new FakeTransport())
            ->json(200, $this->markersResponse())  // 1: markers (init)
            ->json(200, $this->continueWatching())  // 2: resume (init)
            ->json(200, ['tracks' => $tracks])      // 3: subtitle tracks (on `c`)
            ->raw(200, $vtt);                        // 4: the WebVTT body
        [$screen] = $this->screen(transport: $transport);
        $ready = $this->ready($screen);

        [$on, $cmd] = $ready->update(new KeyMsg(KeyType::Char, 'c'));
        foreach ($this->runBatch($cmd) as $msg) {
            [$on] = $on->update($msg);
        }

        return $on;
    }

    public function testCaptionToggleFetchesAndShowsTheActiveCue(): void
    {
        $on = $this->readyWithCaptions(
            [['index' => 0, 'default' => true, 'language' => 'eng', 'label' => 'English', 'codec' => 'subrip']],
            "WEBVTT\n\n00:00:00.000 --> 00:00:20.000\nHello caption",
        );

        self::assertTrue($on->captionsOn());
        self::assertTrue($on->hasCaptions());
        self::assertStringContainsString('Hello caption', $on->view(), 'the active cue is shown at position 0');
        self::assertStringContainsString('cc✓', $on->view(), 'the status hint marks captions on');
    }

    public function testCaptionToggleOffHidesTheCue(): void
    {
        $on = $this->readyWithCaptions(
            [['index' => 0, 'default' => true, 'language' => 'eng', 'label' => 'English', 'codec' => 'subrip']],
            "WEBVTT\n\n00:00:00.000 --> 00:00:20.000\nHello caption",
        );

        [$off] = $on->update(new KeyMsg(KeyType::Char, 'c'));

        self::assertFalse($off->captionsOn());
        self::assertStringNotContainsString('Hello caption', $off->view());
    }

    public function testNoCaptionShownInACueGap(): void
    {
        $on = $this->readyWithCaptions(
            [['index' => 0, 'default' => true, 'language' => 'eng', 'label' => 'English', 'codec' => 'subrip']],
            "WEBVTT\n\n00:00:05.000 --> 00:00:08.000\nLater caption",
        );

        // Position 0 is before the only cue (5–8s) → nothing shown, but captions are on.
        self::assertTrue($on->captionsOn());
        self::assertStringNotContainsString('Later caption', $on->view());
    }

    public function testNoSubtitleTracksLeavesCaptionsOff(): void
    {
        $on = $this->readyWithCaptions([], 'unused');

        self::assertFalse($on->captionsOn(), 'nothing to show → captions stay off');
        self::assertFalse($on->hasCaptions());

        // A second `c` after the empty fetch is a no-op (doesn't refetch).
        [$same, $cmd] = $on->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertSame($on, $same);
        self::assertNull($cmd);
    }

    public function testCaptionFetchFailureIsSwallowed(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->markersResponse())
            ->json(200, $this->continueWatching())
            ->fail(new \RuntimeException('boom')); // subtitle-tracks fetch fails
        [$screen] = $this->screen(transport: $transport);
        $ready = $this->ready($screen);

        [$on, $cmd] = $ready->update(new KeyMsg(KeyType::Char, 'c'));
        foreach ($this->runBatch($cmd) as $msg) {
            [$on] = $on->update($msg);
        }

        self::assertFalse($on->captionsOn(), 'a failed fetch leaves captions off');
        self::assertFalse($on->hasCaptions());
    }

    // ---- up-next (episode queue) ---------------------------------------

    private function episodeItem(string $id): MediaItem
    {
        return MediaItem::fromArray([
            'id' => $id,
            'name' => 'Episode',
            'type' => 'episode',
            'parent_id' => 'season-1',
            'season_number' => 1,
            'episode_number' => 2,
            'stream_url' => self::STREAM,
        ]);
    }

    /** A 3-episode season page (the up-next queue). */
    private function episodesPage(): array
    {
        return ['items' => [
            ['id' => 'ep1', 'name' => 'Pilot', 'type' => 'episode', 'season_number' => 1, 'episode_number' => 1],
            ['id' => 'ep2', 'name' => 'Ep 2', 'type' => 'episode', 'season_number' => 1, 'episode_number' => 2, 'episode_title' => 'The Middle'],
            ['id' => 'ep3', 'name' => 'Finale', 'type' => 'episode', 'season_number' => 1, 'episode_number' => 3],
        ], 'total' => 3];
    }

    /** A ready episode player with its sibling queue loaded ($frameCount 0 ends on the first tick). */
    private function readyEpisode(string $id, int $frameCount = 60): PlayerScreen
    {
        $transport = (new FakeTransport())
            ->json(200, $this->markersResponse())     // 1: markers
            ->json(200, $this->continueWatching())     // 2: resume (none)
            ->json(200, $this->episodesPage());        // 3: siblings
        $decoder = new FakePlayerDecoder($this->frames($frameCount));
        $factory = static fn (string $u, int $c, int $r): Player => Player::openForTest($decoder, fps: 24.0, totalFrames: 2400, cellsW: $c, cellsH: $r, videoPath: '/fake', paused: true);
        $api = new ApiClient('https://srv', $transport);
        $screen = new PlayerScreen($this->episodeItem($id), 'https://srv', $api, $factory, cols: 80, rows: 24);

        return $this->ready($screen);
    }

    public function testEpisodeKnowsItsNeighbours(): void
    {
        $first = $this->readyEpisode('ep1');
        self::assertTrue($first->hasNext());
        self::assertFalse($first->hasPrev());

        $mid = $this->readyEpisode('ep2');
        self::assertTrue($mid->hasNext());
        self::assertTrue($mid->hasPrev());

        $last = $this->readyEpisode('ep3');
        self::assertFalse($last->hasNext());
        self::assertTrue($last->hasPrev());
    }

    public function testEpisodeEndStartsTheUpNextCountdown(): void
    {
        $first = $this->readyEpisode('ep1', frameCount: 0); // empty decoder → ends on first tick

        [$ended, $cmd] = $first->update(new ReelTickMsg());

        self::assertSame(8, $ended->upNextCountdown());
        self::assertNotNull($cmd, 'the countdown tick is armed');
        $view = $ended->view();
        self::assertStringContainsString('Up next', $view);
        self::assertStringContainsString('S01E02 The Middle', $view, 'the next episode is named');
    }

    public function testUpNextCountsDownAndAutoAdvances(): void
    {
        $cur = $this->readyEpisode('ep1', frameCount: 0);
        [$cur] = $cur->update(new ReelTickMsg()); // upNext = 8
        for ($i = 0; $i < 7; $i++) {
            [$cur] = $cur->update(new UpNextTickMsg());
        }
        self::assertSame(1, $cur->upNextCountdown());

        [, $cmd] = $cur->update(new UpNextTickMsg()); // 1 → 0 → advance
        $advance = $this->firstOfType($this->runBatch($cmd), PlayNextMsg::class);

        self::assertInstanceOf(PlayNextMsg::class, $advance);
        self::assertSame('ep2', $advance->item->id);
    }

    public function testUpNextCancelsWhenScrubbedBack(): void
    {
        $first = $this->readyEpisode('ep1', frameCount: 0);
        [$ended] = $first->update(new ReelTickMsg());
        self::assertSame(8, $ended->upNextCountdown());

        // ← seeks back, clearing the ended state.
        [$scrubbed] = $ended->update(new KeyMsg(KeyType::Left));
        self::assertFalse($scrubbed->player()?->ended ?? true, 'seeking cleared ended');

        [$cancelled, $cmd] = $scrubbed->update(new UpNextTickMsg());
        self::assertNull($cancelled->upNextCountdown(), 'the countdown cancels once no longer ended');
        self::assertNull($cmd, 'no further countdown tick');
    }

    public function testNoUpNextOnTheLastEpisode(): void
    {
        $last = $this->readyEpisode('ep3', frameCount: 0);

        [$ended, $cmd] = $last->update(new ReelTickMsg());

        self::assertNull($ended->upNextCountdown(), 'no up-next without a next episode');
        self::assertNull($cmd, 'the ended player just stops');
    }

    public function testNAdvancesToTheNextEpisode(): void
    {
        $mid = $this->readyEpisode('ep2');

        [, $cmd] = $mid->update(new KeyMsg(KeyType::Char, 'n'));
        $advance = $this->firstOfType($this->runBatch($cmd), PlayNextMsg::class);

        self::assertSame('ep3', $advance?->item->id);
    }

    public function testPGoesToThePreviousEpisode(): void
    {
        $mid = $this->readyEpisode('ep2');

        [, $cmd] = $mid->update(new KeyMsg(KeyType::Char, 'p'));
        $advance = $this->firstOfType($this->runBatch($cmd), PlayNextMsg::class);

        self::assertSame('ep1', $advance?->item->id);
    }

    public function testUpNextCardUsesThePlainNameWhenNoEpisodeNumber(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->markersResponse())
            ->json(200, $this->continueWatching())
            ->json(200, ['items' => [
                ['id' => 'cur', 'name' => 'Current', 'type' => 'episode', 'season_number' => 1, 'episode_number' => 1],
                ['id' => 'spec', 'name' => 'A Special'], // no season/episode numbers
            ], 'total' => 2]);
        $decoder = new FakePlayerDecoder($this->frames(0));
        $factory = static fn (string $u, int $c, int $r): Player => Player::openForTest($decoder, fps: 24.0, totalFrames: 2400, cellsW: $c, cellsH: $r, videoPath: '/fake', paused: true);
        $api = new ApiClient('https://srv', $transport);
        $item = MediaItem::fromArray(['id' => 'cur', 'name' => 'Current', 'type' => 'episode', 'parent_id' => 'season-1', 'stream_url' => self::STREAM]);
        $screen = $this->ready(new PlayerScreen($item, 'https://srv', $api, $factory, cols: 80, rows: 24));

        [$ended] = $screen->update(new ReelTickMsg());

        self::assertStringContainsString('A Special', $ended->view(), 'a numberless next episode shows its name');
    }

    public function testMovieHasNoNeighboursAndNKeyIsANoOp(): void
    {
        $ready = $this->ready($this->screen()[0]); // a movie

        self::assertFalse($ready->hasNext());
        self::assertFalse($ready->hasPrev());

        [$same, $cmd] = $ready->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertSame($ready, $same);
        self::assertNull($cmd);
    }

    // ---- resume --------------------------------------------------------

    /** A ready screen whose continue-watching says this item is $atSeconds into a 100s clip. */
    private function readyResumed(float $atSeconds): PlayerScreen
    {
        $transport = (new FakeTransport())
            ->json(200, $this->markersResponse())
            ->json(200, $this->continueWatching([
                $this->watchedRow('m1', (int) ($atSeconds * 10_000_000), 100 * 10_000_000),
            ]));
        [$screen] = $this->screen(transport: $transport);

        return $this->ready($screen);
    }

    public function testResumeSeeksToTheSavedPositionAndShowsTheHint(): void
    {
        $resumed = $this->readyResumed(60.0);

        self::assertTrue($resumed->isResumed());
        self::assertSame(60.0, $resumed->position());
        self::assertSame(60.0, $resumed->resumeSeconds());
        self::assertStringContainsString('Resumed from 1:00', $resumed->view());
        self::assertStringContainsString('start over', $resumed->view());
    }

    public function testNoResumeWithoutASavedPosition(): void
    {
        // The default screen()'s continue-watching call returns {} → no items.
        $ready = $this->ready($this->screen()[0]);

        self::assertFalse($ready->isResumed());
        self::assertSame(0.0, $ready->position());
    }

    public function testNoResumeWhenNearlyComplete(): void
    {
        // 98s of 100s → 98% > 95% → not resumable.
        $resumed = $this->readyResumed(98.0);

        self::assertFalse($resumed->isResumed());
        self::assertSame(0.0, $resumed->position());
    }

    public function testNoResumeBelowTheFloor(): void
    {
        // 3s in → below the 5s floor → not worth resuming.
        $resumed = $this->readyResumed(3.0);

        self::assertFalse($resumed->isResumed());
        self::assertSame(0.0, $resumed->position());
    }

    public function testStartOverSeeksToZeroAndDismissesTheHint(): void
    {
        $resumed = $this->readyResumed(60.0);
        self::assertTrue($resumed->isResumed());

        [$over] = $resumed->update(new KeyMsg(KeyType::Char, 'o'));

        self::assertSame(0.0, $over->position());
        self::assertNull($over->resumeSeconds(), 'the resume hint is dismissed');
        self::assertStringNotContainsString('Resumed from', $over->view());
    }

    public function testResumeHintAutoDismissesAfterWatchingPast(): void
    {
        $resumed = $this->readyResumed(20.0);
        self::assertStringContainsString('Resumed from', $resumed->view());

        // Seek well past the resume point + its hint window (20 + 45s).
        $cur = $resumed;
        for ($i = 0; $i < 6; $i++) {
            [$cur] = $cur->update(new KeyMsg(KeyType::Right)); // +10s each → 80s
        }

        self::assertSame(80.0, $cur->position());
        self::assertStringNotContainsString('Resumed from', $cur->view(), 'the hint auto-dismisses');
        self::assertTrue($cur->isResumed(), 'still flagged resumed; only the hint is gone');
    }

    public function testResumeFetchFailureIsSwallowed(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->markersResponse())
            ->fail(new \RuntimeException('boom')); // continue-watching fails
        [$screen] = $this->screen(transport: $transport);

        $ready = $this->ready($screen);

        self::assertFalse($ready->isResumed());
        self::assertSame(0.0, $ready->position(), 'plays from the start');
    }

    public function testResumeAppliesEvenIfInfoArrivesBeforeReady(): void
    {
        // Order the messages by hand: ResumeInfo first, then PlayerReady.
        [$screen, $decoder] = $this->screen();
        [$withResume] = $screen->update(new ResumeInfoMsg(42.0));
        self::assertFalse($withResume->isResumed(), 'cannot resume until the player exists');

        $player = Player::openForTest($decoder, fps: 24.0, totalFrames: 2400, cellsW: 80, cellsH: 18, videoPath: '/fake', paused: true);
        [$ready] = $withResume->update(new PlayerReadyMsg($player));

        self::assertTrue($ready->isResumed());
        self::assertSame(42.0, $ready->position(), 'onReady applies the pending resume');
    }

    // ---- progress reporting / session lifecycle ------------------------

    public function testPlaybackOpensASessionWithTheDeviceId(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->markersResponse())
            ->json(200, $this->continueWatching())
            ->json(201, ['session_id' => 'sess-1']);

        $ready = $this->readyWithSession($transport);

        self::assertSame('sess-1', $ready->sessionId());
        $sessionReq = $transport->requestAt(2); // 0 = markers, 1 = continue-watching, 2 = session
        self::assertSame('POST', $sessionReq['method']);
        self::assertStringContainsString('/api/v1/sessions', $sessionReq['url']);
        self::assertStringContainsString('device_id', $sessionReq['body']);
    }

    public function testProgressTickReportsThePositionInTicks(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->markersResponse())
            ->json(200, $this->continueWatching())
            ->json(201, ['session_id' => 'sess-1'])
            ->json(200, ['message' => 'ok']);
        $ready = $this->readyWithSession($transport);

        [$moved] = $ready->update(new KeyMsg(KeyType::Right)); // → 10s
        [, $cmd] = $moved->update(new ProgressTickMsg());
        $this->runBatch($cmd); // fires the progress POST + re-arms the heartbeat

        $req = $transport->lastRequest();
        self::assertNotNull($req);
        self::assertSame('POST', $req['method']);
        self::assertStringContainsString('/sessions/sess-1/progress', $req['url']);
        self::assertStringContainsString('"position_ticks":100000000', $req['body'], '10s × 10,000,000 ticks/s');
        self::assertStringContainsString('"media_item_id":"m1"', $req['body']);
    }

    public function testSessionCreateFailureIsSwallowedAndPlaybackContinues(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->markersResponse())
            ->json(200, $this->continueWatching())
            ->fail(new \RuntimeException('boom')); // session create fails

        $ready = $this->readyWithSession($transport);

        self::assertNull($ready->sessionId(), 'a failed session is swallowed');
        self::assertTrue($ready->isPlaying(), 'playback continues regardless');
    }

    public function testExitReportsAFinalPositionAndEndsTheSession(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->markersResponse())
            ->json(200, $this->continueWatching())
            ->json(201, ['session_id' => 'sess-1'])
            ->json(200, ['message' => 'ok'])     // final progress
            ->json(200, ['message' => 'ended']); // endSession
        $ready = $this->readyWithSession($transport);

        [, $cmd] = $ready->update(new KeyMsg(KeyType::Escape));
        $msgs = $this->runBatch($cmd);

        self::assertNotNull($this->firstOfType($msgs, NavigateBackMsg::class), 'still navigates back');
        $calls = array_map(static fn (array $r): string => $r['method'] . ' ' . $r['url'], $transport->requests);
        self::assertNotEmpty(array_filter($calls, static fn (string $c): bool => str_contains($c, 'POST') && str_contains($c, '/sessions/sess-1/progress')), 'final progress reported');
        self::assertNotEmpty(array_filter($calls, static fn (string $c): bool => str_starts_with($c, 'DELETE') && str_contains($c, '/sessions/sess-1')), 'session ended');
    }

    public function testExitWithoutASessionJustNavigatesBack(): void
    {
        // ready() discards onReady's session Cmd → no session opened.
        $ready = $this->ready($this->screen()[0]);
        self::assertNull($ready->sessionId());

        [, $cmd] = $ready->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke(), 'a plain back, no session calls');
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

    /**
     * @param list<Msg> $msgs
     * @param class-string $class
     */
    private function firstOfType(array $msgs, string $class): ?Msg
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof $class) {
                return $msg;
            }
        }

        return null;
    }

    /** @return list<Msg> the settled Msgs of a (possibly batched) Cmd */
    private function runBatch(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }
        $result = $cmd();

        if ($result instanceof BatchMsg) {
            $msgs = [];
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                if ($msg !== null) {
                    $msgs[] = $msg;
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

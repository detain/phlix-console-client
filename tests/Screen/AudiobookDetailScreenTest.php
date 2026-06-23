<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Msg\AudiobookChaptersLoadedMsg;
use Phlix\Console\Msg\AudiobookFailedMsg;
use Phlix\Console\Msg\AudiobookLoadedMsg;
use Phlix\Console\Msg\AudiobookProgressLoadedMsg;
use Phlix\Console\Msg\AudiobookTickMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AudiobookDetailScreen;
use Phlix\Console\Store\AudiobooksStore;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Tests\Reel\FakeAudioPlayer;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Toast\ToastType;

final class AudiobookDetailScreenTest extends TestCase
{
    private const STREAM = 'https://srv/api/v1/audiobooks/ab1/stream?sig=s';

    /** The most recent fake audio player the factory produced. */
    private ?FakeAudioPlayer $lastPlayer = null;
    /** Every [url, startMs] the audio factory was handed (in order). @var list<array{0:string,1:?int}> */
    private array $plays = [];

    private function screenWith(FakeTransport $transport): AudiobookDetailScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $factory = function (string $url, ?int $startMs): FakeAudioPlayer {
            $this->plays[] = [$url, $startMs];

            return $this->lastPlayer = new FakeAudioPlayer($url);
        };

        return new AudiobookDetailScreen(new AudiobooksStore($api), 'https://srv', $factory, 'ab1', 'Dune', cols: 120, rows: 40);
    }

    /** The `{ "audiobook": { … } }` detail envelope with sensible defaults. */
    private function detailResponse(array $overrides = []): array
    {
        return ['audiobook' => array_merge([
            'id' => 'ab1',
            'title' => 'Dune',
            'author' => 'Frank Herbert',
            'narrator' => 'Scott Brick',
            'series' => 'Dune',
            'series_position' => 1,
            'description' => 'A desert planet.',
            'duration_ms' => 75_600_000, // 21:00:00
            'language' => 'English',
            'stream_url' => self::STREAM,
        ], $overrides)];
    }

    /** The `{ "chapters": [ … ] }` envelope with two chapters. */
    private function chaptersResponse(): array
    {
        return ['chapters' => [
            ['index' => 0, 'title' => 'Beginnings', 'start_ms' => 0, 'end_ms' => 3_600_000, 'duration_ms' => 3_600_000],
            ['index' => 1, 'title' => 'The Spice', 'start_ms' => 3_600_000, 'end_ms' => 7_200_000, 'duration_ms' => 3_600_000],
        ]];
    }

    /** The `{ "progress": { … } }` envelope (defaults to never-played, no resume). */
    private function progressResponse(array $overrides = []): array
    {
        return ['progress' => array_merge([
            'audiobook_id' => 'ab1',
            'user_id' => 'u1',
            'position_ms' => 0,
            'current_chapter_index' => 0,
            'completed_chapters' => [],
            'percent_complete' => 0.0,
            'last_played_at' => null,
        ], $overrides)];
    }

    /**
     * Build a screen, run its init batch (detail, chapters, progress in queue
     * order), and feed the resolved messages back. Returns the loaded screen.
     */
    private function loaded(array $detailOverrides = [], ?array $chaptersBody = null, ?array $progressBody = null): AudiobookDetailScreen
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse($detailOverrides))
            ->json(200, $chaptersBody ?? $this->chaptersResponse())
            ->json(200, $progressBody ?? $this->progressResponse());
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());
        foreach ($msgs as $msg) {
            [$screen] = $screen->update($msg);
        }

        return $screen;
    }

    // ---- init: three fetches -------------------------------------------

    public function testInitBatchesAllThreeFetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->json(200, $this->chaptersResponse())
            ->json(200, $this->progressResponse());
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());

        // Three fetches: the detail, the chapter list, and the saved progress.
        self::assertCount(3, $msgs);
        self::assertInstanceOf(AudiobookLoadedMsg::class, $msgs[0]);
        self::assertInstanceOf(AudiobookChaptersLoadedMsg::class, $msgs[1]);
        self::assertInstanceOf(AudiobookProgressLoadedMsg::class, $msgs[2]);
        self::assertStringEndsWith('/api/v1/audiobooks/ab1', $transport->requestAt(0)['url']);
        self::assertStringEndsWith('/api/v1/audiobooks/ab1/chapters', $transport->requestAt(1)['url']);
        self::assertStringEndsWith('/api/v1/audiobooks/ab1/progress', $transport->requestAt(2)['url']);
    }

    public function testProgressSetsResumeAndSelectsTheSavedChapter(): void
    {
        // A saved position of 1h00m00s in chapter 1 → resume offered + chapter 1 selected.
        $loaded = $this->loaded(progressBody: $this->progressResponse([
            'position_ms' => 3_600_000,
            'current_chapter_index' => 1,
        ]));

        self::assertSame(3_600_000, $loaded->resumeMs());
        self::assertSame(1, $loaded->selectedIndex(), 'the saved chapter is pre-selected');

        // The resume hint shows (not playing yet).
        $view = $loaded->view();
        self::assertStringContainsString('Resume from 1:00:00', $view);
        self::assertStringContainsString('(press r)', $view);
    }

    public function testZeroProgressOffersNoResume(): void
    {
        $loaded = $this->loaded(); // default progress position_ms = 0

        self::assertNull($loaded->resumeMs());
        self::assertStringNotContainsString('Resume from', $loaded->view());
    }

    public function testProgressBeforeChaptersResumesAndSelectsZeroWithNoChapters(): void
    {
        // Progress can resolve before the chapter list (or with none at all): a
        // resume is still offered and the selection clamps to 0 (no chapters to
        // index into; the chapters-load clamp will re-clamp later).
        $screen = $this->screenWith((new FakeTransport())->pending());

        [$withProgress] = $screen->update(new AudiobookProgressLoadedMsg(
            \Phlix\Console\Api\Dto\AudiobookProgress::fromArray([
                'audiobook_id' => 'ab1',
                'position_ms' => 4242,
                'current_chapter_index' => 7, // would clamp, but no chapters yet → 0
            ]),
        ));

        self::assertSame(4242, $withProgress->resumeMs());
        self::assertSame(0, $withProgress->selectedIndex(), 'no chapters loaded → selection stays 0');
    }

    public function testProgressFetchAuthErrorBecomesSessionExpired(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->json(200, $this->chaptersResponse())
            ->json(401, ['error' => 'unauthorized']);
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());

        self::assertNotSame([], array_filter($msgs, static fn (Msg $m): bool => $m instanceof SessionExpiredMsg), 'a progress auth error expires the session');
    }

    public function testProgressFetchNonAuthErrorIsSwallowedSoNoResume(): void
    {
        // A non-auth progress failure must NOT error the screen and must offer no resume.
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->json(200, $this->chaptersResponse())
            ->fail(new \RuntimeException('progress down'));
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());
        // Only the detail + chapters messages — the progress error dispatched nothing.
        self::assertCount(2, $msgs);
        self::assertSame([], array_filter($msgs, static fn (Msg $m): bool => $m instanceof AudiobookProgressLoadedMsg));

        foreach ($msgs as $msg) {
            [$screen] = $screen->update($msg);
        }
        self::assertNull($screen->error(), 'a progress failure is not a screen error');
        self::assertNull($screen->resumeMs(), 'no resume offered');
        self::assertStringContainsString('by Frank Herbert', $screen->view(), 'the meta still shows');
    }

    // ---- A2 behaviour kept intact --------------------------------------

    public function testLoadedRendersMetaLinesAndChapterTable(): void
    {
        $loaded = $this->loaded();

        self::assertTrue($loaded->isLoaded());
        self::assertSame('ab1', $loaded->audiobook()?->id);

        $view = $loaded->view();
        self::assertStringContainsString('by Frank Herbert', $view);
        self::assertStringContainsString('narrated by Scott Brick', $view);
        self::assertStringContainsString('Dune #1', $view);
        self::assertStringContainsString('21:00:00', $view);
        self::assertStringContainsString('English', $view);
        self::assertStringContainsString('Chapter', $view);
        self::assertStringContainsString('Duration', $view);
        self::assertStringContainsString('Beginnings', $view);
        self::assertStringContainsString('The Spice', $view);
        self::assertStringContainsString('1:00:00', $view, '3_600_000ms → 1:00:00');
    }

    public function testSeriesWithoutPositionOmitsTheHash(): void
    {
        $view = $this->loaded(['series_position' => null])->view();

        self::assertStringContainsString('Dune', $view);
        self::assertStringNotContainsString('Dune #', $view, 'no position → no # suffix');
    }

    public function testMetaLineOneOmittedWhenAuthorAndNarratorMissing(): void
    {
        $view = $this->loaded(['author' => null, 'narrator' => null])->view();

        self::assertStringNotContainsString('by ', $view, 'no "by"/"narrated by" line');
        self::assertStringContainsString('Dune #1', $view, 'the series line still shows');
    }

    public function testMetaLineTwoOmittedWhenSeriesDurationLanguageMissing(): void
    {
        $view = $this->loaded([
            'series' => null,
            'series_position' => null,
            'duration_ms' => null,
            'language' => null,
        ])->view();

        self::assertStringContainsString('by Frank Herbert', $view);
        self::assertStringNotContainsString('English', $view);
        self::assertStringNotContainsString('Dune #', $view, 'no series-position segment');
        self::assertStringNotContainsString('21:00:00', $view, 'no duration segment');
    }

    public function testZeroDurationChapterRendersZeroClock(): void
    {
        $view = $this->loaded(chaptersBody: ['chapters' => [
            ['index' => 0, 'title' => 'Intro', 'start_ms' => 0, 'end_ms' => 0, 'duration_ms' => 0],
        ]])->view();

        self::assertStringContainsString('Intro', $view);
        self::assertStringContainsString('0:00', $view, 'a zero chapter duration renders as 0:00');
    }

    public function testEmptyChaptersShowTheNoChaptersNotice(): void
    {
        $loaded = $this->loaded(chaptersBody: ['chapters' => []]);

        self::assertSame([], $loaded->chapters());
        $view = $loaded->view();
        self::assertStringContainsString('No chapters.', $view);
        self::assertStringContainsString('by Frank Herbert', $view);
    }

    public function testLoadingViewBeforeTheDetailArrives(): void
    {
        $view = $this->screenWith((new FakeTransport())->pending())->view();

        self::assertStringContainsString('Loading', $view);
        self::assertStringContainsString('Dune', $view, 'the seed title fills the header during load');
    }

    public function testDetailFetchFailureShowsAnError(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, ['chapters' => []])
            ->json(200, $this->progressResponse());
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());
        self::assertInstanceOf(AudiobookFailedMsg::class, $msgs[0]);

        foreach ($msgs as $msg) {
            [$screen] = $screen->update($msg);
        }
        self::assertStringContainsString('Could not load', $screen->view());
        self::assertStringContainsString('Could not load', (string) $screen->error());
    }

    public function testChaptersFetchFailureDegradesToEmptyKeepingTheMeta(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->fail(new \RuntimeException('chapters down'))
            ->json(200, $this->progressResponse());
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());
        self::assertInstanceOf(AudiobookLoadedMsg::class, $msgs[0]);
        self::assertInstanceOf(AudiobookChaptersLoadedMsg::class, $msgs[1]);
        self::assertSame([], $msgs[1]->chapters);

        foreach ($msgs as $msg) {
            [$screen] = $screen->update($msg);
        }
        self::assertNull($screen->error(), 'a chapters failure is not a screen error');
        $view = $screen->view();
        self::assertStringContainsString('No chapters.', $view);
        self::assertStringContainsString('by Frank Herbert', $view, 'the meta still shows');
    }

    public function testDetailAuthErrorBecomesSessionExpired(): void
    {
        $transport = (new FakeTransport())
            ->json(401, ['error' => 'unauthorized'])
            ->json(200, ['chapters' => []])
            ->json(200, $this->progressResponse());
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());

        self::assertNotSame([], array_filter($msgs, static fn (Msg $m): bool => $m instanceof SessionExpiredMsg), 'a detail auth error expires the session');
    }

    public function testChaptersAuthErrorBecomesSessionExpired(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->json(401, ['error' => 'unauthorized'])
            ->json(200, $this->progressResponse());
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());

        self::assertNotSame([], array_filter($msgs, static fn (Msg $m): bool => $m instanceof SessionExpiredMsg), 'a chapters auth error expires the session');
    }

    public function testDownAndUpMoveTheSelectionAndClampOverChapters(): void
    {
        $loaded = $this->loaded();
        self::assertSame(0, $loaded->selectedIndex());

        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());
        self::assertSame('The Spice', $down->selectedChapter()?->title);

        [$clamped, $cmd] = $down->update(new KeyMsg(KeyType::Down));
        self::assertSame($down, $clamped, 'a clamped move is a no-op');
        self::assertNull($cmd);

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex());
        [$topClamped] = $up->update(new KeyMsg(KeyType::Up));
        self::assertSame($up, $topClamped, 'up at the top is a no-op');
    }

    public function testArrowsWithNoChaptersAreNoOps(): void
    {
        $loaded = $this->loaded(chaptersBody: ['chapters' => []]);

        [$down, $downCmd] = $loaded->update(new KeyMsg(KeyType::Down));
        [$up, $upCmd] = $loaded->update(new KeyMsg(KeyType::Up));

        self::assertSame($loaded, $down);
        self::assertSame($loaded, $up);
        self::assertNull($downCmd);
        self::assertNull($upCmd);
    }

    public function testEscapeAndQNavigateBack(): void
    {
        $loaded = $this->loaded();

        [, $escCmd] = $loaded->update(new KeyMsg(KeyType::Escape));
        [, $qCmd] = $loaded->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertInstanceOf(NavigateBackMsg::class, $escCmd?->__invoke());
        self::assertInstanceOf(NavigateBackMsg::class, $qCmd?->__invoke());
    }

    public function testUnhandledKeyAndMessageAreNoOps(): void
    {
        $loaded = $this->loaded();

        [$afterKey, $keyCmd] = $loaded->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($loaded, $afterKey);
        self::assertNull($keyCmd);

        [$afterMsg, $msgCmd] = $loaded->update(new \Phlix\Console\Msg\OpenSearchMsg());
        self::assertSame($loaded, $afterMsg);
        self::assertNull($msgCmd);
    }

    public function testResizeStillRenders(): void
    {
        [$small] = $this->loaded()->update(new WindowSizeMsg(60, 20));
        self::assertIsString($small->view());

        [$big] = $this->loaded()->update(new WindowSizeMsg(100, 40));
        self::assertStringContainsString('Beginnings', $big->view());
    }

    public function testBreadcrumbLabelIsTheAudiobookTitle(): void
    {
        self::assertSame('Dune', $this->loaded()->crumbLabel());

        $view = $this->loaded()->withCrumbs(['Home', 'Listens', 'Dune'])->view();
        self::assertStringContainsString('Dune', $view);
        self::assertStringContainsString('›', $view);
    }

    public function testBreadcrumbFallsBackToTheSeedTitleBeforeLoad(): void
    {
        $screen = $this->screenWith((new FakeTransport())->pending());

        self::assertSame('Dune', $screen->crumbLabel());
    }

    public function testSelectionClampsWhenChaptersReloadSmaller(): void
    {
        $loaded = $this->loaded();
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());

        [$reloaded] = $down->update(new AudiobookChaptersLoadedMsg([$loaded->chapters()[0]]));

        self::assertSame(0, $reloaded->selectedIndex(), 'the cursor is clamped into the smaller chapter list');
    }

    public function testDetailReloadClampsSelectionToChapterCount(): void
    {
        $loaded = $this->loaded();
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());

        [$reAudiobook] = $down->update(new AudiobookLoadedMsg($loaded->audiobook()));

        self::assertSame(1, $reAudiobook->selectedIndex(), 'still within the 2-chapter list');
    }

    // ---- audio: play (synchronous) -------------------------------------

    public function testEnterPlaysTheSelectedChapterFromItsStart(): void
    {
        // Select the second chapter (starts at 1h), then Enter plays from 3_600_000ms.
        $loaded = $this->loaded();
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));

        [$playing, $tick] = $down->update(new KeyMsg(KeyType::Enter));

        // Play is synchronous: the factory was called immediately with the
        // resolved absolute URL + the chapter's start offset, and start() ran.
        self::assertCount(1, $this->plays);
        self::assertSame(self::STREAM, $this->plays[0][0], 'the signed absolute URL is used verbatim');
        self::assertSame(3_600_000, $this->plays[0][1], 'the second chapter starts at 1h');
        self::assertNotNull($this->lastPlayer);
        self::assertSame(1, $this->lastPlayer->startCalls, 'the player was started');

        self::assertTrue($playing->isPlaying());
        self::assertFalse($playing->isPaused());
        self::assertSame(3_600_000, $playing->positionMs(), 'position starts at the chapter offset');
        self::assertNotNull($tick, 'the position tick is armed');

        // The now-playing line replaces the meta/resume lines.
        $view = $playing->view();
        self::assertStringContainsString('▶ The Spice', $view);
        self::assertStringContainsString('1:00:00 / 21:00:00', $view, 'position / total');
        self::assertStringNotContainsString('Frank Herbert', $view, 'the meta line is replaced while playing');
    }

    public function testEnterOnAChapterlessAudiobookPlaysFromZero(): void
    {
        $loaded = $this->loaded(chaptersBody: ['chapters' => []]);

        [$playing, $tick] = $loaded->update(new KeyMsg(KeyType::Enter));

        self::assertCount(1, $this->plays, 'a chapterless audiobook still plays');
        self::assertSame(0, $this->plays[0][1], 'from the very start');
        self::assertTrue($playing->isPlaying());
        self::assertSame(0, $playing->positionMs());
        self::assertNotNull($tick);
        // The now-playing line falls back to the audiobook title (no chapters).
        self::assertStringContainsString('▶ Dune', $playing->view());
    }

    public function testRelativeStreamUrlIsResolvedAgainstTheBase(): void
    {
        $loaded = $this->loaded(['stream_url' => '/api/v1/audiobooks/ab1/stream?sig=x']);

        [$playing] = $loaded->update(new KeyMsg(KeyType::Enter));

        self::assertSame('https://srv/api/v1/audiobooks/ab1/stream?sig=x', $this->plays[0][0]);
        self::assertTrue($playing->isPlaying());
    }

    public function testMissingStreamUrlSurfacesAnErrorToastAndPlaysNothing(): void
    {
        $loaded = $this->loaded(['stream_url' => null]);

        [$after, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));

        $toast = $cmd?->__invoke();
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Cannot play', $toast->message);
        self::assertFalse($after->isPlaying(), 'nothing starts playing');
        self::assertCount(0, $this->plays, 'the factory is never called');
    }

    // ---- audio: resume -------------------------------------------------

    public function testResumeKeyPlaysFromTheSavedPosition(): void
    {
        $loaded = $this->loaded(progressBody: $this->progressResponse([
            'position_ms' => 5_400_000, // 1:30:00
            'current_chapter_index' => 1,
        ]));

        [$playing, $tick] = $loaded->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertCount(1, $this->plays);
        self::assertSame(5_400_000, $this->plays[0][1], 'resume seeks to the saved position');
        self::assertTrue($playing->isPlaying());
        self::assertSame(5_400_000, $playing->positionMs());
        self::assertNotNull($tick);
    }

    public function testResumeKeyWithNoSavedPositionIsANoOp(): void
    {
        $loaded = $this->loaded(); // no resume offered

        [$same, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertSame($loaded, $same);
        self::assertNull($cmd);
        self::assertCount(0, $this->plays);
    }

    public function testResumeKeyWithNullStreamUrlSurfacesAnErrorToast(): void
    {
        // A resume is offered, but the stream URL is missing → error toast, no play.
        $loaded = $this->loaded(
            ['stream_url' => null],
            null,
            $this->progressResponse(['position_ms' => 1000]),
        );

        [$after, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'r'));

        $toast = $cmd?->__invoke();
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertFalse($after->isPlaying());
        self::assertCount(0, $this->plays);
    }

    // ---- audio: pause / resume toggle ----------------------------------

    public function testEnterOnThePlayingChapterTogglesPause(): void
    {
        $playing = $this->playing();
        self::assertStringContainsString('▶ Beginnings', $playing->view());

        // Enter again (the playing chapter is still selected) → pause + a save.
        [$paused, $pauseCmd] = $playing->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($paused->isPaused());
        self::assertSame(1, $this->lastPlayer?->pauseCalls);
        self::assertInstanceOf(\Closure::class, $pauseCmd, 'pausing fires a progress save');
        $this->runCmd($pauseCmd); // a save POST, no Msg
        self::assertStringContainsString('⏸ Beginnings', $paused->view());

        // Enter once more → resume + re-arm the tick.
        [$resumed, $resumeCmd] = $paused->update(new KeyMsg(KeyType::Enter));
        self::assertFalse($resumed->isPaused());
        self::assertSame(1, $this->lastPlayer?->resumeCalls);
        self::assertNotNull($resumeCmd, 'resuming re-arms the tick');
        self::assertStringContainsString('▶ Beginnings', $resumed->view());
    }

    public function testSpaceTogglesPause(): void
    {
        $playing = $this->playing();

        [$paused, $pauseCmd] = $playing->update(new KeyMsg(KeyType::Char, ' '));
        self::assertTrue($paused->isPaused());
        self::assertSame(1, $this->lastPlayer?->pauseCalls);
        self::assertInstanceOf(\Closure::class, $pauseCmd, 'pause saves progress');

        [$resumed, $resumeCmd] = $paused->update(new KeyMsg(KeyType::Char, ' '));
        self::assertFalse($resumed->isPaused());
        self::assertSame(1, $this->lastPlayer?->resumeCalls);
        self::assertNotNull($resumeCmd);
    }

    public function testSpaceWithNothingPlayingIsANoOp(): void
    {
        $loaded = $this->loaded();

        [$same, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, ' '));

        self::assertSame($loaded, $same);
        self::assertNull($cmd);
    }

    public function testThePauseSaveCarriesTheCurrentPosition(): void
    {
        // Pause records a POST with the current position_ms / chapter / percent.
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->json(200, $this->chaptersResponse())
            ->json(200, $this->progressResponse())
            ->json(200, $this->progressResponse()); // the save POST response
        $screen = $this->screenWith($transport);
        foreach ($this->runBatch($screen->init()) as $msg) {
            [$screen] = $screen->update($msg);
        }
        $playing = $this->startAndPlay($screen); // plays from 0
        [$ticked] = $playing->update(new AudiobookTickMsg($playing->audioEpoch())); // +1000ms

        [, $pauseCmd] = $ticked->update(new KeyMsg(KeyType::Char, ' '));
        $this->runCmd($pauseCmd);

        $post = $transport->requestAt(3);
        self::assertSame('POST', $post['method']);
        $body = json_decode($post['body'], true);
        self::assertSame(1000, $body['position_ms']);
        self::assertSame(0, $body['current_chapter_index'], 'still in chapter 0');
    }

    // ---- audio: tick / position ----------------------------------------

    public function testAudiobookTickAdvancesTheEstimatedPosition(): void
    {
        $cur = $this->playing();
        for ($i = 0; $i < 5; $i++) {
            [$cur, $cmd] = $cur->update(new AudiobookTickMsg($cur->audioEpoch()));
            self::assertNotNull($cmd, 'each playing tick re-arms the next');
        }

        self::assertSame(5000, $cur->positionMs(), 'five 1-second ticks = 5000ms');
        self::assertStringContainsString('0:05 / 21:00:00', $cur->view(), 'position renders as m:ss');
    }

    public function testTickWhilePausedDoesNotAdvanceOrRearm(): void
    {
        $playing = $this->playing();
        [$paused] = $playing->update(new KeyMsg(KeyType::Char, ' '));

        [$same, $cmd] = $paused->update(new AudiobookTickMsg($paused->audioEpoch()));

        self::assertSame($paused, $same, 'a paused tick is inert');
        self::assertNull($cmd, 'no re-arm while paused');
        self::assertSame(0, $same->positionMs());
    }

    public function testTickWithNothingPlayingIsANoOp(): void
    {
        $loaded = $this->loaded();

        [$same, $cmd] = $loaded->update(new AudiobookTickMsg($loaded->audioEpoch()));

        self::assertSame($loaded, $same);
        self::assertNull($cmd);
    }

    public function testAStaleTickFromASupersededGenerationIsDropped(): void
    {
        // Regression (mirrors AlbumScreen): a leftover tick from a previous
        // heartbeat must NOT advance the position or arm a second heartbeat.
        $cur = $this->playing();
        $staleEpoch = $cur->audioEpoch();
        [$cur] = $cur->update(new AudiobookTickMsg($cur->audioEpoch())); // +1000ms, same generation

        [$paused] = $cur->update(new KeyMsg(KeyType::Char, ' '));    // bump epoch, pause
        [$resumed, $arm] = $paused->update(new KeyMsg(KeyType::Char, ' ')); // bump epoch, resume
        self::assertNotNull($arm, 'resume arms a fresh heartbeat');
        self::assertNotSame($staleEpoch, $resumed->audioEpoch(), 'the generation advanced');

        // The leftover tick from the original generation is ignored.
        [$afterStale, $staleCmd] = $resumed->update(new AudiobookTickMsg($staleEpoch));
        self::assertSame($resumed->positionMs(), $afterStale->positionMs(), 'a stale tick does not advance the position');
        self::assertNull($staleCmd, 'a stale tick does not arm a second heartbeat');

        // The live generation's tick still advances exactly once (+1000ms).
        [$afterLive] = $resumed->update(new AudiobookTickMsg($resumed->audioEpoch()));
        self::assertSame($resumed->positionMs() + 1000, $afterLive->positionMs());
    }

    // ---- audio: throttled progress reporting ---------------------------

    public function testEveryTenTicksPostsAThrottledProgressSave(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->json(200, $this->chaptersResponse())
            ->json(200, $this->progressResponse())
            ->json(200, $this->progressResponse()); // the throttled save POST
        $screen = $this->screenWith($transport);
        foreach ($this->runBatch($screen->init()) as $msg) {
            [$screen] = $screen->update($msg);
        }
        $cur = $this->startAndPlay($screen);

        // Nine ticks: no save yet (each re-arms only).
        for ($i = 0; $i < 9; $i++) {
            [$cur, $cmd] = $cur->update(new AudiobookTickMsg($cur->audioEpoch()));
            self::assertNotNull($cmd);
            self::assertSame(3, $transport->requestCount(), 'no save before the 10th tick');
        }

        // The 10th tick's Cmd is a batch (tick + report) → it POSTs the save.
        [$cur, $tenth] = $cur->update(new AudiobookTickMsg($cur->audioEpoch()));
        self::assertSame(10_000, $cur->positionMs());
        $this->drainBatch($tenth);

        self::assertSame(4, $transport->requestCount(), 'the 10th tick saves progress');
        $post = $transport->requestAt(3);
        self::assertSame('POST', $post['method']);
        self::assertStringEndsWith('/api/v1/audiobooks/ab1/progress', $post['url']);
        $body = json_decode($post['body'], true);
        self::assertSame(10_000, $body['position_ms']);
        self::assertSame(0, $body['current_chapter_index'], '10s in → still chapter 0');
        self::assertSame([], $body['completed_chapters'], 'no chapter finished yet');
        self::assertEqualsWithDelta(10_000 / 75_600_000 * 100, $body['percent_complete'], 0.0001);
    }

    // ---- audio: finishing the book -------------------------------------

    public function testReachingTheDurationStopsAndFiresAFinalReport(): void
    {
        // A tiny audiobook (2-second duration) so the tick reaches the end fast.
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse(['duration_ms' => 2000]))
            ->json(200, ['chapters' => [['index' => 0, 'title' => 'Only', 'start_ms' => 0, 'end_ms' => 2000, 'duration_ms' => 2000]]])
            ->json(200, $this->progressResponse())
            ->json(200, $this->progressResponse()); // the final save POST
        $screen = $this->screenWith($transport);
        foreach ($this->runBatch($screen->init()) as $msg) {
            [$screen] = $screen->update($msg);
        }
        $cur = $this->startAndPlay($screen);
        $player = $this->lastPlayer;

        [$cur, $cmd1] = $cur->update(new AudiobookTickMsg($cur->audioEpoch())); // 1000ms
        self::assertNotNull($cmd1);
        [$finished, $finalCmd] = $cur->update(new AudiobookTickMsg($cur->audioEpoch())); // 2000ms == duration → finish

        self::assertFalse($finished->isPlaying(), 'playback stops at the end');
        self::assertSame(1, $player?->stopCalls, 'the player is stopped');
        self::assertInstanceOf(\Closure::class, $finalCmd, 'a final report fires');
        $this->runCmd($finalCmd);

        $post = $transport->requestAt(3);
        self::assertSame('POST', $post['method']);
        $body = json_decode($post['body'], true);
        self::assertSame(2000, $body['position_ms']);
        self::assertEqualsWithDelta(100.0, $body['percent_complete'], 0.0001, 'a finished book reports ~100%');
        self::assertSame([0], $body['completed_chapters'], 'the only chapter is complete');

        // The header reverts to the metadata once stopped.
        self::assertStringContainsString('by Frank Herbert', $finished->view());
    }

    // ---- audio: teardown -----------------------------------------------

    public function testEscapeWhilePlayingSavesProgressTearsDownAndNavigatesBack(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->json(200, $this->chaptersResponse())
            ->json(200, $this->progressResponse())
            ->json(200, $this->progressResponse()); // the final save POST
        $screen = $this->screenWith($transport);
        foreach ($this->runBatch($screen->init()) as $msg) {
            [$screen] = $screen->update($msg);
        }
        $playing = $this->startAndPlay($screen);
        $player = $this->lastPlayer;
        [$ticked] = $playing->update(new AudiobookTickMsg($playing->audioEpoch())); // +1000ms

        [, $cmd] = $ticked->update(new KeyMsg(KeyType::Escape));

        // The Cmd batches a final save POST + the NavigateBack.
        $nav = $this->drainBatch($cmd);
        self::assertInstanceOf(NavigateBackMsg::class, $nav);
        self::assertSame(1, $player?->stopCalls, 'leaving stops the audio (no leaked ffplay)');
        self::assertSame(4, $transport->requestCount(), 'a final progress save was POSTed');
        $body = json_decode($transport->requestAt(3)['body'], true);
        self::assertSame(1000, $body['position_ms']);
    }

    public function testEscapeWithNothingPlayingJustNavigatesBack(): void
    {
        $loaded = $this->loaded();

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
        self::assertCount(0, $this->plays);
    }

    public function testTeardownIsIdempotent(): void
    {
        $playing = $this->playing();
        $player = $this->lastPlayer;

        $playing->teardown();
        $playing->teardown(); // must not double-stop or throw

        self::assertSame(1, $player?->stopCalls);
    }

    // ---- audio: progress computation (focused) -------------------------

    public function testProgressFieldsAreComputedFromThePositionAndChapterBoundaries(): void
    {
        // Chapter 0 = [0, 3_600_000), chapter 1 = [3_600_000, 7_200_000).
        $loaded = $this->loaded(['duration_ms' => 7_200_000]);

        // Play from chapter 1's start, then advance one tick into it.
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        [$playing] = $down->update(new KeyMsg(KeyType::Enter)); // position 3_600_000
        [$ticked] = $playing->update(new AudiobookTickMsg($playing->audioEpoch())); // 3_601_000

        self::assertSame(1, $ticked->currentChapterIndexForTest(), 'position is inside chapter 1');
        self::assertSame([0], $ticked->completedChapterIndicesForTest(), 'chapter 0 is fully behind');
        self::assertEqualsWithDelta(3_601_000 / 7_200_000 * 100, $ticked->percentCompleteForTest(), 0.0001);
    }

    public function testPercentCompleteIsZeroWhenDurationUnknown(): void
    {
        $loaded = $this->loaded(['duration_ms' => null]);
        [$playing] = $loaded->update(new KeyMsg(KeyType::Enter));

        self::assertSame(0.0, $playing->percentCompleteForTest(), 'no duration → 0%');
    }

    // ---- audio test helpers --------------------------------------------

    /** Drive Enter on the default (chapter-0-selected) loaded screen → playing. */
    private function playing(): AudiobookDetailScreen
    {
        return $this->startAndPlay($this->loaded());
    }

    /** Enter → the now-playing screen (synchronous play, so just feed Enter). */
    private function startAndPlay(AudiobookDetailScreen $screen): AudiobookDetailScreen
    {
        [$playing, $tick] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($playing->isPlaying(), 'Enter starts playback');
        self::assertNotNull($tick);

        return $playing;
    }

    /** Run a (possibly batched) Cmd and return the first non-tick Msg it yields. */
    private function drainBatch(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }
        $result = $cmd();
        if ($result instanceof BatchMsg) {
            $found = null;
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                // Ignore the re-armed tick; return the meaningful Msg (nav/etc).
                if ($msg !== null && !$msg instanceof AudiobookTickMsg) {
                    $found = $msg;
                }
            }

            return $found;
        }

        return $result instanceof Msg ? $result : null;
    }

    // ---- async Cmd runners (mirror AlbumScreenTest) --------------------

    /** @return list<Msg> */
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
            return $this->await($result->promise);
        }

        return $result instanceof Msg ? $result : null;
    }

    private function await(PromiseInterface $promise, float $timeout = 5.0): mixed
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
            // The promise settled synchronously (the AudiobooksStore wraps the
            // sync FakeTransport in a Deferred). React may still have enqueued the
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

        if (!$state['done']) {
            throw new \RuntimeException('cmd did not settle in time');
        }
        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Msg\AudiobookChaptersLoadedMsg;
use Phlix\Console\Msg\AudiobookFailedMsg;
use Phlix\Console\Msg\AudiobookLoadedMsg;
use Phlix\Console\Msg\AudiobookProgressLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\PlayAudiobookMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ToggleAudioMsg;
use Phlix\Console\Screen\AudiobookDetailScreen;
use Phlix\Console\Store\AudiobooksStore;
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
 * The AudiobookDetailScreen is now a pure chapter list — the AUDIO is owned by
 * the App (an AudiobookSession), so this suite covers the meta + chapter table
 * render, the resume affordance, and the Msgs the screen EMITS (PlayAudiobookMsg
 * on Enter/r, ToggleAudioMsg on Space, NavigateBack on Esc/q). The audio/progress
 * behaviour itself moved to AppTest.
 */
final class AudiobookDetailScreenTest extends TestCase
{
    private const STREAM = 'https://srv/api/v1/audiobooks/ab1/stream?sig=s';

    private function screenWith(FakeTransport $transport): AudiobookDetailScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new AudiobookDetailScreen(new AudiobooksStore($api), 'ab1', 'Dune', cols: 120, rows: 40);
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

        // The resume hint shows.
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

    // ---- meta + chapter table render -----------------------------------

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

    // ---- selection ------------------------------------------------------

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

    // ---- emitted Msgs (the App owns the audio) -------------------------

    public function testEnterEmitsPlayAudiobookForTheSelectedChapterStart(): void
    {
        // Select the second chapter (starts at 1h), then Enter emits a play at 3_600_000ms.
        $loaded = $this->loaded();
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));

        [$same, $cmd] = $down->update(new KeyMsg(KeyType::Enter));

        self::assertSame($down, $same, 'the screen does not change — the App plays the audio');
        $msg = $cmd?->__invoke();
        self::assertInstanceOf(PlayAudiobookMsg::class, $msg);
        self::assertSame($down->audiobook(), $msg->audiobook, 'the loaded audiobook is carried');
        self::assertSame($down->chapters(), $msg->chapters, 'the chapter list is carried');
        self::assertSame(3_600_000, $msg->startMs, 'the second chapter starts at 1h');
    }

    public function testEnterOnAChapterlessAudiobookEmitsPlayFromZero(): void
    {
        $loaded = $this->loaded(chaptersBody: ['chapters' => []]);

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));

        $msg = $cmd?->__invoke();
        self::assertInstanceOf(PlayAudiobookMsg::class, $msg);
        self::assertSame([], $msg->chapters);
        self::assertSame(0, $msg->startMs, 'a chapterless audiobook plays from the very start');
    }

    public function testEnterBeforeTheAudiobookLoadsIsANoOp(): void
    {
        // No detail yet → no stream URL to play; Enter emits nothing (the App also
        // guards a missing URL, but the screen shouldn't emit before it loads).
        $screen = $this->screenWith((new FakeTransport())->pending());

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testSpaceEmitsToggleAudio(): void
    {
        $loaded = $this->loaded();

        [$same, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, ' '));

        self::assertSame($loaded, $same);
        self::assertInstanceOf(ToggleAudioMsg::class, $cmd?->__invoke());
    }

    public function testResumeKeyEmitsPlayAudiobookFromTheSavedPosition(): void
    {
        $loaded = $this->loaded(progressBody: $this->progressResponse([
            'position_ms' => 5_400_000, // 1:30:00
            'current_chapter_index' => 1,
        ]));

        [$same, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertSame($loaded, $same);
        $msg = $cmd?->__invoke();
        self::assertInstanceOf(PlayAudiobookMsg::class, $msg);
        self::assertSame(5_400_000, $msg->startMs, 'resume seeks to the saved position');
    }

    public function testResumeKeyWithNoSavedPositionIsANoOp(): void
    {
        $loaded = $this->loaded(); // no resume offered

        [$same, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertSame($loaded, $same);
        self::assertNull($cmd);
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

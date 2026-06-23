<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Msg\AudiobookChaptersLoadedMsg;
use Phlix\Console\Msg\AudiobookFailedMsg;
use Phlix\Console\Msg\AudiobookLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
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
use SugarCraft\Toast\ToastType;

final class AudiobookDetailScreenTest extends TestCase
{
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
            'stream_url' => 'https://srv/api/v1/audiobooks/ab1/stream?sig=s',
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

    /**
     * Build a screen, run its init batch (detail then chapters, in queue order),
     * and feed BOTH resolved messages back. Returns the fully-loaded screen.
     */
    private function loaded(array $detailOverrides = [], ?array $chaptersBody = null): AudiobookDetailScreen
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse($detailOverrides))
            ->json(200, $chaptersBody ?? $this->chaptersResponse());
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());
        foreach ($msgs as $msg) {
            [$screen] = $screen->update($msg);
        }

        return $screen;
    }

    public function testInitBatchesBothFetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->json(200, $this->chaptersResponse());
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());

        // Two fetches: the detail and the chapter list.
        self::assertCount(2, $msgs);
        self::assertInstanceOf(AudiobookLoadedMsg::class, $msgs[0]);
        self::assertInstanceOf(AudiobookChaptersLoadedMsg::class, $msgs[1]);
        self::assertStringEndsWith('/api/v1/audiobooks/ab1', $transport->requestAt(0)['url']);
        self::assertStringEndsWith('/api/v1/audiobooks/ab1/chapters', $transport->requestAt(1)['url']);
    }

    public function testLoadedRendersMetaLinesAndChapterTable(): void
    {
        $loaded = $this->loaded();

        self::assertTrue($loaded->isLoaded());
        self::assertSame('ab1', $loaded->audiobook()?->id);

        $view = $loaded->view();
        // Meta line 1: by author · narrated by narrator.
        self::assertStringContainsString('by Frank Herbert', $view);
        self::assertStringContainsString('narrated by Scott Brick', $view);
        // Meta line 2: series #position · duration · language.
        self::assertStringContainsString('Dune #1', $view);
        self::assertStringContainsString('21:00:00', $view);
        self::assertStringContainsString('English', $view);
        // Chapter table: headers + each chapter's number, title and duration.
        self::assertStringContainsString('Chapter', $view);
        self::assertStringContainsString('Duration', $view);
        self::assertStringContainsString('Beginnings', $view);
        self::assertStringContainsString('The Spice', $view);
        self::assertStringContainsString('1:00:00', $view, '3_600_000ms → 1:00:00');
        // The chapter number column is 1-based off the (0-based) index.
        self::assertStringContainsString('1', $view);
        self::assertStringContainsString('2', $view);
    }

    public function testSeriesWithoutPositionOmitsTheHash(): void
    {
        $view = $this->loaded(['series_position' => null])->view();

        self::assertStringContainsString('Dune', $view);
        self::assertStringNotContainsString('Dune #', $view, 'no position → no # suffix');
    }

    public function testMetaLineOneOmittedWhenAuthorAndNarratorMissing(): void
    {
        // No author, no narrator → the whole first meta line is omitted, but the
        // second line (series/duration/language) still renders.
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

        // Line one still shows; nothing from line two (series #pos / duration /
        // language) leaks in. (The chapter table's "#" header is unrelated.)
        self::assertStringContainsString('by Frank Herbert', $view);
        self::assertStringNotContainsString('English', $view);
        self::assertStringNotContainsString('Dune #', $view, 'no series-position segment');
        self::assertStringNotContainsString('21:00:00', $view, 'no duration segment');
    }

    public function testZeroDurationChapterRendersZeroClock(): void
    {
        // An AudiobookChapter's durationLabel() never returns '' (a zero duration
        // renders 0:00), so a 0ms chapter shows 0:00 rather than the dash.
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
        // The metadata still renders even with no chapters.
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
        // The detail fetch fails (non-auth) → a whole-screen error. The chapters
        // fetch resolves empty; the error takes precedence in the view.
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, ['chapters' => []]);
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
        // A non-auth chapters error must NOT blank the whole screen — it degrades
        // to an empty chapter list while the metadata still renders.
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->fail(new \RuntimeException('chapters down'));
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());
        // The chapters fetch yields an (empty) AudiobookChaptersLoadedMsg, not a failure.
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
            ->json(200, ['chapters' => []]);
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());

        self::assertNotSame([], array_filter($msgs, static fn (Msg $m): bool => $m instanceof SessionExpiredMsg), 'a detail auth error expires the session');
    }

    public function testChaptersAuthErrorBecomesSessionExpired(): void
    {
        // Even though a non-auth chapters error degrades to empty, an AUTH error
        // on the chapters fetch still surfaces as a session expiry.
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse())
            ->json(401, ['error' => 'unauthorized']);
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

        // Down again clamps at the last chapter (only two).
        [$clamped, $cmd] = $down->update(new KeyMsg(KeyType::Down));
        self::assertSame($down, $clamped, 'a clamped move is a no-op');
        self::assertNull($cmd);

        // Up at the top clamps at 0.
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

    public function testEnterWithChaptersShowsTheComingSoonToast(): void
    {
        // Enter is inert this PR: an info toast placeholder (A3 wires real audio).
        $loaded = $this->loaded();

        [$same, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));

        $toast = $cmd?->__invoke();
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Info, $toast->type);
        self::assertStringContainsString('next update', $toast->message);
        self::assertSame($loaded, $same, 'the placeholder does not mutate the screen');
    }

    public function testEnterWithNoChaptersIsANoOp(): void
    {
        $loaded = $this->loaded(chaptersBody: ['chapters' => []]);

        [$same, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));

        self::assertSame($loaded, $same);
        self::assertNull($cmd, 'no chapters → nothing to play');
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

        [$afterMsg, $msgCmd] = $loaded->update(new SessionExpiredMsg('ignored here'));
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
        // Before the detail resolves the crumb is the seed title passed in.
        $screen = $this->screenWith((new FakeTransport())->pending());

        self::assertSame('Dune', $screen->crumbLabel());
    }

    public function testSelectionClampsWhenChaptersReloadSmaller(): void
    {
        $loaded = $this->loaded();
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());

        // A fresh, single-chapter result lands → the cursor clamps to 0.
        [$reloaded] = $down->update(new AudiobookChaptersLoadedMsg([$loaded->chapters()[0]]));

        self::assertSame(0, $reloaded->selectedIndex(), 'the cursor is clamped into the smaller chapter list');
    }

    public function testDetailReloadClampsSelectionToChapterCount(): void
    {
        // The detail message also clamps the selection (to the current chapters).
        $loaded = $this->loaded();
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());

        [$reAudiobook] = $down->update(new AudiobookLoadedMsg($loaded->audiobook()));

        self::assertSame(1, $reAudiobook->selectedIndex(), 'still within the 2-chapter list');
    }

    // ---- async Cmd runners (mirror BookDetailScreenTest) ---------------

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

    private function runCmd(\Closure $cmd): ?Msg
    {
        $result = $cmd();
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

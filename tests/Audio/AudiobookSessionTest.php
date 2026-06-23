<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Audio;

use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Audio\AudiobookSession;
use Phlix\Console\Tests\Reel\FakeAudioPlayer;
use PHPUnit\Framework\TestCase;

final class AudiobookSessionTest extends TestCase
{
    private function audiobook(array $overrides = []): Audiobook
    {
        return Audiobook::fromArray(array_merge([
            'id' => 'ab1',
            'title' => 'Dune',
            'author' => 'Frank Herbert',
            'narrator' => 'Scott Brick',
            'series' => 'Dune',
            'series_position' => 1,
            'description' => 'A desert planet.',
            'duration_ms' => 7_200_000, // 2:00:00
            'language' => 'English',
            'stream_url' => 'https://srv/s/ab1',
        ], $overrides));
    }

    /** Two chapters: [0, 3_600_000) "Beginnings", [3_600_000, 7_200_000) "The Spice". */
    private function chapters(): array
    {
        return [
            AudiobookChapter::fromArray(['index' => 0, 'title' => 'Beginnings', 'start_ms' => 0, 'end_ms' => 3_600_000, 'duration_ms' => 3_600_000], 0),
            AudiobookChapter::fromArray(['index' => 1, 'title' => 'The Spice', 'start_ms' => 3_600_000, 'end_ms' => 7_200_000, 'duration_ms' => 3_600_000], 1),
        ];
    }

    private function session(
        ?Audiobook $audiobook = null,
        ?array $chapters = null,
        int $positionMs = 0,
        bool $paused = false,
        int $epoch = 1,
        int $ticksSinceReport = 0,
    ): AudiobookSession {
        return new AudiobookSession(
            new FakeAudioPlayer('u'),
            $audiobook ?? $this->audiobook(),
            $chapters ?? $this->chapters(),
            $positionMs,
            $paused,
            $epoch,
            $ticksSinceReport,
        );
    }

    // ---- accessors -----------------------------------------------------

    public function testAccessorsReflectTheConstructorArguments(): void
    {
        $player = new FakeAudioPlayer('u');
        $book = $this->audiobook();
        $chapters = $this->chapters();
        $session = new AudiobookSession($player, $book, $chapters, 4242, true, 7, 3);

        self::assertSame($player, $session->player());
        self::assertSame($book, $session->audiobook());
        self::assertSame($chapters, $session->chapters());
        self::assertSame('ab1', $session->audiobookId(), 'the id is the audiobook id');
        self::assertSame(4242, $session->positionMs());
        self::assertTrue($session->paused());
        self::assertSame(7, $session->epoch());
        self::assertSame(3, $session->ticksSinceReport());
    }

    public function testTitleIsTheCurrentChapterTitle(): void
    {
        // Position inside chapter 1 → its title.
        self::assertSame('The Spice', $this->session(positionMs: 3_600_000)->title());
        // Position inside chapter 0 → its title.
        self::assertSame('Beginnings', $this->session(positionMs: 0)->title());
    }

    public function testTitleFallsBackToTheAudiobookTitleWithoutChapters(): void
    {
        self::assertSame('Dune', $this->session(chapters: [], positionMs: 1000)->title());
    }

    public function testSubtitleIsAuthorAndNarrator(): void
    {
        self::assertSame('Frank Herbert · Scott Brick', $this->session()->subtitle());
    }

    public function testSubtitleOmitsAMissingNarrator(): void
    {
        $session = $this->session($this->audiobook(['narrator' => null]));

        self::assertSame('Frank Herbert', $session->subtitle());
    }

    public function testSubtitleFallsBackToTheTitleWhenAuthorAndNarratorMissing(): void
    {
        $session = $this->session($this->audiobook(['author' => null, 'narrator' => null]));

        self::assertSame('Dune', $session->subtitle());
    }

    // ---- interface: labels / ticked / endReached -----------------------

    public function testPositionAndDurationLabelsFormatAsClocks(): void
    {
        $session = $this->session(positionMs: 3_661_000); // 1:01:01 of a 2:00:00 book

        self::assertSame('1:01:01', $session->positionLabel());
        self::assertSame('2:00:00', $session->durationLabel());
    }

    public function testDurationLabelIsADashWhenUnknown(): void
    {
        $session = $this->session($this->audiobook(['duration_ms' => null]), positionMs: 5000);

        self::assertSame('0:05', $session->positionLabel());
        self::assertSame('—', $session->durationLabel());
    }

    public function testTickedAdvancesByOneSecondAndCountsAReportTick(): void
    {
        $session = $this->session(positionMs: 4000, ticksSinceReport: 2);
        $ticked = $session->ticked();

        self::assertNotSame($session, $ticked);
        self::assertSame(4000, $session->positionMs(), 'the original is unchanged');
        self::assertSame(5000, $ticked->positionMs(), '+1000ms per heartbeat');
        self::assertSame(3, $ticked->ticksSinceReport(), 'the throttle counter advances too');
    }

    public function testEndReachedOnceThePositionMeetsTheDuration(): void
    {
        self::assertFalse($this->session(positionMs: 7_199_999)->endReached());
        self::assertTrue($this->session(positionMs: 7_200_000)->endReached());
        self::assertTrue($this->session(positionMs: 9_000_000)->endReached());
    }

    public function testEndReachedIsFalseForAnUnknownDuration(): void
    {
        $session = $this->session($this->audiobook(['duration_ms' => null]), positionMs: 99_999_999);

        self::assertFalse($session->endReached(), 'no duration → never ends');
    }

    // ---- audiobook-specific progress -----------------------------------

    public function testCurrentChapterIndexLocatesThePositionWithinChapterBounds(): void
    {
        self::assertSame(0, $this->session(positionMs: 0)->currentChapterIndex());
        self::assertSame(0, $this->session(positionMs: 3_599_999)->currentChapterIndex());
        self::assertSame(1, $this->session(positionMs: 3_600_000)->currentChapterIndex());
        self::assertSame(1, $this->session(positionMs: 7_199_999)->currentChapterIndex());
    }

    public function testCurrentChapterIndexIsZeroWithNoChapters(): void
    {
        self::assertSame(0, $this->session(chapters: [], positionMs: 5_000_000)->currentChapterIndex());
    }

    public function testCurrentChapterIndexFallsToTheLastStartedChapterPastTheEnd(): void
    {
        // A position past the last chapter's end falls back to the last chapter
        // that started at/before it.
        self::assertSame(1, $this->session(positionMs: 8_000_000)->currentChapterIndex());
    }

    public function testCompletedChapterIndicesAreTheOnesFullyBehind(): void
    {
        self::assertSame([], $this->session(positionMs: 0)->completedChapterIndices());
        self::assertSame([], $this->session(positionMs: 3_599_999)->completedChapterIndices());
        self::assertSame([0], $this->session(positionMs: 3_600_000)->completedChapterIndices());
        self::assertSame([0, 1], $this->session(positionMs: 7_200_000)->completedChapterIndices());
    }

    public function testPercentCompleteIsThePositionOverTheDuration(): void
    {
        self::assertSame(0.0, $this->session(positionMs: 0)->percentComplete());
        self::assertEqualsWithDelta(50.0, $this->session(positionMs: 3_600_000)->percentComplete(), 0.0001);
        self::assertSame(100.0, $this->session(positionMs: 7_200_000)->percentComplete(), 'clamped at 100');
        self::assertSame(100.0, $this->session(positionMs: 9_000_000)->percentComplete(), 'never above 100');
    }

    public function testPercentCompleteIsZeroWhenDurationUnknown(): void
    {
        $session = $this->session($this->audiobook(['duration_ms' => null]), positionMs: 5000);

        self::assertSame(0.0, $session->percentComplete());
    }

    public function testShouldReportEveryTenTicks(): void
    {
        self::assertFalse($this->session(ticksSinceReport: 9)->shouldReport());
        self::assertTrue($this->session(ticksSinceReport: 10)->shouldReport());
        self::assertTrue($this->session(ticksSinceReport: 11)->shouldReport());
    }

    public function testWithReportedResetsTheThrottleCounter(): void
    {
        $session = $this->session(ticksSinceReport: 10);
        $reported = $session->withReported();

        self::assertNotSame($session, $reported);
        self::assertSame(10, $session->ticksSinceReport(), 'the original is unchanged');
        self::assertSame(0, $reported->ticksSinceReport());
    }

    // ---- clone-mutate --------------------------------------------------

    public function testWithPausedIsImmutable(): void
    {
        $session = $this->session(paused: false);
        $paused = $session->withPaused(true);

        self::assertNotSame($session, $paused);
        self::assertFalse($session->paused());
        self::assertTrue($paused->paused());
    }

    public function testWithEpochIsImmutable(): void
    {
        $session = $this->session(epoch: 1);
        $bumped = $session->withEpoch(2);

        self::assertNotSame($session, $bumped);
        self::assertSame(1, $session->epoch());
        self::assertSame(2, $bumped->epoch());
    }

    public function testWithPositionMsIsImmutable(): void
    {
        $session = $this->session(positionMs: 0);
        $moved = $session->withPositionMs(5000);

        self::assertNotSame($session, $moved);
        self::assertSame(0, $session->positionMs());
        self::assertSame(5000, $moved->positionMs());
    }

    // ---- teardown ------------------------------------------------------

    public function testTeardownStopsThePlayer(): void
    {
        $player = new FakeAudioPlayer('u');
        $session = new AudiobookSession($player, $this->audiobook(), $this->chapters(), 0, false, 1);

        $session->teardown();

        self::assertSame(1, $player->stopCalls);
    }

    public function testTeardownIsIdempotent(): void
    {
        $player = new FakeAudioPlayer('u');
        $session = new AudiobookSession($player, $this->audiobook(), $this->chapters(), 0, false, 1);

        $session->teardown();
        $session->teardown(); // must not double-stop or throw

        self::assertSame(1, $player->stopCalls);
    }
}

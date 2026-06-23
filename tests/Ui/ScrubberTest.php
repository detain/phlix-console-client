<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Api\Dto\Chapter;
use Phlix\Console\Ui\Scrubber;
use PHPUnit\Framework\TestCase;

final class ScrubberTest extends TestCase
{
    /** Strip SGR so the bar glyphs can be counted. */
    private function plain(string $s): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $s);
    }

    public function testRendersClocksAndABar(): void
    {
        $out = $this->plain(Scrubber::of(30.0, 100.0, 60)->render());

        self::assertStringContainsString('0:30', $out, 'position clock');
        self::assertStringContainsString('1:40', $out, 'duration clock');
        self::assertStringContainsString('█', $out, 'filled portion');
        self::assertStringContainsString('░', $out, 'empty portion');
    }

    public function testFillIsProportionalToPosition(): void
    {
        $half = $this->plain(Scrubber::of(50.0, 100.0, 60)->render());
        $full = $this->plain(Scrubber::of(100.0, 100.0, 60)->render());

        $halfFilled = substr_count($half, '█');
        $fullFilled = substr_count($full, '█');

        self::assertGreaterThan(0, $halfFilled);
        self::assertGreaterThan($halfFilled, $fullFilled, 'further position → more fill');
        self::assertSame(0, substr_count($full, '░'), 'at the end the bar is entirely filled');
    }

    public function testChapterTicksAppearAtBoundaries(): void
    {
        $chapters = [
            new Chapter(0.0, 50.0, 'One'),   // at 0 → no tick (the start)
            new Chapter(50.0, 100.0, 'Two'), // mid-bar → a tick
        ];
        $out = $this->plain(Scrubber::of(0.0, 100.0, 60, $chapters)->render());

        self::assertStringContainsString('│', $out, 'an interior chapter boundary ticks');
    }

    public function testUnknownDurationShowsPlaceholderAndNoFill(): void
    {
        $out = $this->plain(Scrubber::of(0.0, 0.0, 60)->render());

        self::assertStringContainsString('--:--', $out, 'duration unknown');
        self::assertSame(0, substr_count($out, '█'), 'no fill without a known duration');
    }

    public function testPositionBeyondDurationClampsToFull(): void
    {
        $out = $this->plain(Scrubber::of(999.0, 100.0, 60)->render());

        self::assertSame(0, substr_count($out, '░'), 'an over-run position clamps to a full bar');
    }

    public function testHourLongClockFormat(): void
    {
        $out = $this->plain(Scrubber::of(3661.0, 7200.0, 80)->render());

        self::assertStringContainsString('1:01:01', $out, '3661s → 1:01:01');
        self::assertStringContainsString('2:00:00', $out, '7200s → 2:00:00');
    }
}

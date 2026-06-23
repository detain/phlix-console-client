<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Chrome;
use PHPUnit\Framework\TestCase;

final class ChromeTest extends TestCase
{
    public function testFrameWithoutATrailShowsTheBareTitle(): void
    {
        $out = Chrome::frame('Movies', 'the body', 'a hint', 80, 24);

        self::assertStringContainsString('Movies', $out);
        self::assertStringContainsString('the body', $out);
        self::assertStringNotContainsString('›', $out, 'no breadcrumb separator without a trail');
    }

    public function testFrameWithATrailRendersTheBreadcrumb(): void
    {
        $out = Chrome::frame('The Matrix', 'body', 'hint', 100, 24, ['Home', 'Movies', 'The Matrix']);

        self::assertStringContainsString('Home', $out);
        self::assertStringContainsString('Movies', $out);
        self::assertStringContainsString('The Matrix', $out);
        self::assertStringContainsString('›', $out, 'breadcrumb separators are present');
    }

    public function testADeepTrailTruncatesFromTheLeftKeepingTheCurrentCrumb(): void
    {
        $trail = ['Home', 'Movies', 'Very Long Series Title', 'Season 10', 'S10E24 A Long Episode Title'];

        // A narrow terminal can't fit the whole path; the deepest crumb must stay.
        $out = Chrome::frame('S10E24', 'body', 'hint', 48, 24, $trail);

        self::assertStringContainsString('S10E24', $out, 'the current location stays visible');
    }

    public function testAnEmptyTrailIsTreatedAsNoTrail(): void
    {
        $out = Chrome::frame('Login', 'body', 'hint', 80, 24, []);

        self::assertStringContainsString('Login', $out);
        self::assertStringNotContainsString('›', $out);
    }

    public function testContentHeightIsPositiveAndGrowsWithRows(): void
    {
        $small = Chrome::contentHeight(80, 24);
        $large = Chrome::contentHeight(80, 50);

        self::assertGreaterThan(0, $small);
        self::assertLessThan(24, $small, 'the content region is only a fraction of the rows');
        self::assertGreaterThan($small, $large, 'a taller terminal yields a taller content region');
    }

    public function testContentHeightIsExactlyWhatTheFrameCanShow(): void
    {
        $rows = 30;
        $h = Chrome::contentHeight(80, $rows);

        // A body of exactly $h tagged lines all survive the frame…
        $fit = implode("\n", array_map(static fn (int $i): string => "ROW{$i}END", range(1, $h)));
        $out = Chrome::frame('T', $fit, 'hint', 80, $rows);
        for ($i = 1; $i <= $h; $i++) {
            self::assertStringContainsString("ROW{$i}END", $out, "row {$i} of {$h} should be visible");
        }

        // …and one more line overflows (the frame cannot show $h + 1).
        $over = implode("\n", array_map(static fn (int $i): string => "ROW{$i}END", range(1, $h + 1)));
        $outOver = Chrome::frame('T', $over, 'hint', 80, $rows);
        self::assertStringNotContainsString('ROW' . ($h + 1) . 'END', $outOver);
    }

    public function testContentHeightIsMemoisedAndStable(): void
    {
        self::assertSame(Chrome::contentHeight(100, 40), Chrome::contentHeight(100, 40));
    }
}

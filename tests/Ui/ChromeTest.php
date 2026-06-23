<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Theme;
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

    public function testBodyHeightIsPositiveAndGrowsWithRows(): void
    {
        $small = Chrome::bodyHeight(24);
        $large = Chrome::bodyHeight(50);

        self::assertGreaterThan(0, $small);
        self::assertGreaterThan($small, $large, 'a taller terminal yields a taller content region');
        // The content panel now FILLS the frame (it grows with the terminal) —
        // not the old ~1/3 split. At 24 rows the body is the clear majority.
        self::assertGreaterThan((int) (24 / 2), $small, 'the content panel fills most of the terminal');
    }

    public function testBodyHeightIsExactlyWhatTheFrameCanShow(): void
    {
        $rows = 30;
        $h = Chrome::bodyHeight($rows);

        // A body of exactly $h tagged lines all survive the frame…
        $fit = implode("\n", array_map(static fn (int $i): string => "ROW{$i}END", range(1, $h)));
        $out = Chrome::frame('T', $fit, 'hint', 80, $rows);
        for ($i = 1; $i <= $h; $i++) {
            self::assertStringContainsString("ROW{$i}END", $out, "row {$i} of {$h} should be visible");
        }

        // …and one more line overflows (the panel cannot show $h + 1).
        $over = implode("\n", array_map(static fn (int $i): string => "ROW{$i}END", range(1, $h + 1)));
        $outOver = Chrome::frame('T', $over, 'hint', 80, $rows);
        self::assertStringNotContainsString('ROW' . ($h + 1) . 'END', $outOver);
    }

    public function testBodyHeightIsDeterministicAndNeverNegative(): void
    {
        self::assertSame(Chrome::bodyHeight(40), Chrome::bodyHeight(40));
        // A terminal too short for the 8-line chrome yields 0 body lines (exactly
        // what the frame shows); one row taller yields 1. Never negative.
        self::assertSame(0, Chrome::bodyHeight(8));
        self::assertSame(1, Chrome::bodyHeight(9));
        self::assertGreaterThanOrEqual(0, Chrome::bodyHeight(1));
    }

    public function testNocturneThemeIsByteIdenticalToNoTheme(): void
    {
        // The whole approach depends on Nocturne being the identity: a frame with
        // the default Nocturne theme MUST equal the frame with no theme at all,
        // byte-for-byte (proves the styling is a true no-op).
        $plain = Chrome::frame('The Matrix', 'body', 'a hint', 100, 24, ['Home', 'Movies', 'The Matrix']);
        $nocturne = Chrome::frame('The Matrix', 'body', 'a hint', 100, 24, ['Home', 'Movies', 'The Matrix'], Theme::nocturne());

        self::assertSame($plain, $nocturne);
    }

    public function testNocturneThemeIsByteIdenticalWithoutATrail(): void
    {
        $plain = Chrome::frame('Login', 'body', 'hint', 80, 24);
        $nocturne = Chrome::frame('Login', 'body', 'hint', 80, 24, [], Theme::nocturne());

        self::assertSame($plain, $nocturne);
    }

    public function testMidnightThemeColoursTheBrandButNotTheBody(): void
    {
        $out = Chrome::frame('Movies', 'BODYMARKER', 'a hint', 80, 24, [], Theme::midnight());

        // The " Phlix " brand token is wrapped in an SGR escape + reset.
        self::assertStringContainsString("\e[", $out, 'Midnight emits SGR');
        self::assertMatchesRegularExpression('/\e\[[0-9;]*m Phlix \e\[0m/', $out, 'the brand is colour-wrapped');
        // The body text is untouched (no SGR injected around it).
        self::assertStringContainsString('BODYMARKER', $out);
    }

    public function testMidnightLeavesTheTitleUntinted(): void
    {
        // Only the brand is accented; the title/breadcrumb stays plain text.
        $out = Chrome::frame('Movies', 'body', 'hint', 80, 24, [], Theme::midnight());

        // The accent escape immediately precedes " Phlix ", never "Movies".
        self::assertDoesNotMatchRegularExpression('/\e\[[0-9;]*mMovies/', $out, 'the title is not tinted');
        self::assertStringContainsString('Movies', $out);
    }

    public function testFrameBodyWidthIsExactlyColsMinusFour(): void
    {
        // Two nested borders (outer frame + content panel) inset the body by 4
        // cells — the width every screen sizes its content to.
        $cols = 40;
        $out = Chrome::frame('T', str_repeat('x', $cols - 4), 'hint', $cols, 24);
        self::assertStringContainsString(str_repeat('x', $cols - 4), $out, 'a cols-4 body line fits exactly');

        // …and one cell more is clipped (proves the inset is exactly 4, not less).
        $outOver = Chrome::frame('T', str_repeat('y', $cols - 3), 'hint', $cols, 24);
        self::assertStringNotContainsString(str_repeat('y', $cols - 3), $outOver, 'a cols-3 body line does not fit');
    }
}

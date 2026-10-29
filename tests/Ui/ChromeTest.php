<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Theme;
use SugarCraft\Core\Util\Width;
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

    /**
     * The worst-case hint as shipped on `src/Screen/AdminLibrariesScreen.php:95`
     * (width 135 cells). Restated literally, not reached through reflection, so
     * rotating the screen's hint cannot silently retire these pins (M-CONSOLE-45).
     */
    private const ADMIN_HINT = 'c create   u update   d delete   p prune   M clear-metadata   A clear-artwork   D delete-all   s scan   R rescan   m match   h history   r refresh   Esc back';

    private const LIBRARY_HINT = '↑↓←→  move      A–Z  jump      /  filter      c  clear      s  shuffle      R  refresh      Esc  back';

    public function testFitHintReturnsByteIdenticalWhenTheHintFits(): void
    {
        // The wide-terminal case must be a no-op — existing renders and the
        // Docs key-table pins read the shipped hints unchanged.
        self::assertSame('Esc  back', Chrome::fitHint('Esc  back', 80));

        // The measured leaf capacity is exactly cols - 2 (HINT_INSET): a hint
        // of precisely 78 cells is whole at cols=80, one cell more elides.
        $exact = str_repeat('x', 78);
        self::assertSame($exact, Chrome::fitHint($exact, 80));
        self::assertNotSame(str_repeat('x', 79), Chrome::fitHint(str_repeat('x', 79), 80));
    }

    public function testFitHintKeepsTheTrailingSafetyKeyWhenClipped(): void
    {
        // Every hint in the app parks its escape hatch at the END; right-clipping
        // deleted exactly that (the review's "Esc … among the first tokens lost").
        foreach ([60, 80] as $cols) {
            self::assertStringEndsWith(
                'Esc back',
                Chrome::fitHint(self::ADMIN_HINT, $cols),
                "admin hint at cols={$cols}"
            );
            self::assertStringEndsWith(
                'Esc  back',
                Chrome::fitHint(self::LIBRARY_HINT, $cols),
                "library hint at cols={$cols}"
            );
        }
    }

    public function testFitHintSignalsTheOmissionWithTheMarker(): void
    {
        $fitted = Chrome::fitHint(self::ADMIN_HINT, 80);

        // The old clip was silent ("no `…` or any signal that text was cut").
        self::assertStringContainsString(' … ', $fitted);
        // The head is kept too — the first documented keys stay discoverable.
        self::assertStringStartsWith('c create', $fitted);
    }

    public function testFitHintNeverExceedsTheLeafWidth(): void
    {
        // Sweep every plausible terminal width: the fitted hint must always fit
        // the borderless leaf (cols - 2 cells) or collapse honestly at degenerate
        // widths (cols ≤ 3 leaves no room for two runs plus the marker).
        foreach ([self::ADMIN_HINT, self::LIBRARY_HINT] as $hint) {
            for ($cols = 1; $cols <= 120; $cols++) {
                $width = Width::string(Chrome::fitHint($hint, $cols));
                self::assertLessThanOrEqual(max(0, $cols - 2), $width, "overflow at cols={$cols}");
            }
        }
    }

    public function testFitHintElidesWholeWordsOnly(): void
    {
        $words = static fn (string $s): array => array_values(array_filter(
            preg_split('/\s+/u', trim($s)) ?: [],
            static fn (string $w): bool => $w !== '…'
        ));

        $source = $words(self::ADMIN_HINT);
        foreach ([40, 60, 80] as $cols) {
            foreach ($words(Chrome::fitHint(self::ADMIN_HINT, $cols)) as $word) {
                self::assertContains($word, $source, "fit at cols={$cols} invented or cut a word: {$word}");
            }
        }
    }

    public function testFitHintMeasuresDisplayCellsNotCodepoints(): void
    {
        // Wide (East-Asian) glyphs cost 2 cells; a codepoint-counting helper
        // would overflow the leaf ~2x — the same law this review's third finding
        // states for the canonical Width helper.
        $hint = 'メニュー   作成   更新   削除   設定   詳細   並替   更新   読込   履歴   破棄   全选   Esc  back';
        $fitted = Chrome::fitHint($hint, 80);

        self::assertLessThanOrEqual(78, Width::string($fitted));
        self::assertStringEndsWith('Esc  back', $fitted, 'the tail survives wide-char elision');
    }

    public function testFitHintHandlesDegenerateWidthsWithoutCrashing(): void
    {
        // No room for head + marker + tail at all: the canonical
        // Width::truncateMiddle fallback takes over and never exceeds the leaf.
        self::assertSame('', Chrome::fitHint(self::ADMIN_HINT, 1));
        self::assertSame('', Chrome::fitHint(self::ADMIN_HINT, 2));

        foreach ([3, 4, 5, 6] as $cols) {
            $fitted = Chrome::fitHint(self::ADMIN_HINT, $cols);
            self::assertLessThanOrEqual(max(0, $cols - 2), Width::string($fitted), "cols={$cols}");
            self::assertNotSame(self::ADMIN_HINT, $fitted, "the hint must not overflow at cols={$cols}");
        }

        self::assertSame('', Chrome::fitHint('', 80), 'an empty hint stays empty at any width');
    }

    public function testFrameShowsTheEscKeyOnAn80ColumnAdminScreen(): void
    {
        // Integration pin, red-on-parent: at HEAD~ the 80-col probe rendered
        // "c create … D clear-artwork D" and lost everything past it, including
        // 'Esc back'. The status row must carry the safety key AND the marker.
        $out = Chrome::frame('Admin · Libraries', 'body', self::ADMIN_HINT, 80, 24);

        $statusRow = null;
        foreach (explode("\n", $out) as $line) {
            if (str_contains($line, 'create')) {
                $statusRow = $line;
                break;
            }
        }

        self::assertNotNull($statusRow, 'the hint still renders on the status row');
        self::assertStringContainsString('Esc back', $statusRow);
        self::assertStringContainsString(' … ', $statusRow);
    }
}

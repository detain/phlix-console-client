<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Skeleton;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

final class SkeletonTest extends TestCase
{
    public function testBarsRendersTheRequestedNumberOfRows(): void
    {
        $bars = Skeleton::bars(20, 4, 0);

        self::assertCount(4, explode("\n", $bars), 'one row per requested line');
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $lines
     */
    #[DataProvider('shapes')]
    public function testEveryRowIsExactlyTheRequestedWidth(int $width, int $lines, int $phase): void
    {
        $bars = Skeleton::bars($width, $lines, $phase);

        foreach (explode("\n", $bars) as $i => $row) {
            self::assertSame($width, Width::of($row), "row {$i} fills exactly {$width} cells at phase {$phase}");
        }
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function shapes(): iterable
    {
        yield 'narrow, one line, phase 0' => [10, 1, 0];
        yield 'standard grid body' => [76, 18, 3];
        yield 'wide, mid-sweep' => [200, 6, 97];
        yield 'phase past the wrap point' => [12, 2, 999];
        yield 'single cell wide' => [1, 3, 5];
        yield 'band-width wide' => [3, 1, 1];
    }

    public function testThemedRowsAreAlsoExactlyTheRequestedWidth(): void
    {
        // A coloured theme wraps the band cells in SGR; the visible width must be
        // unaffected (ANSI-safe).
        foreach (explode("\n", Skeleton::bars(40, 5, 7, Theme::midnight())) as $row) {
            self::assertSame(40, Width::of($row), 'embedded SGR does not count toward cell width');
        }
    }

    public function testNonPositiveDimensionsRenderNothing(): void
    {
        self::assertSame('', Skeleton::bars(0, 5, 0), 'zero width = nothing to draw');
        self::assertSame('', Skeleton::bars(20, 0, 0), 'zero lines = nothing to draw');
        self::assertSame('', Skeleton::bars(-4, 3, 0), 'negative width = nothing to draw');
    }

    public function testRenderIsDeterministic(): void
    {
        // Same (width, lines, phase, theme) → byte-identical output.
        self::assertSame(Skeleton::bars(48, 6, 11), Skeleton::bars(48, 6, 11));
        self::assertSame(
            Skeleton::bars(48, 6, 11, Theme::midnight()),
            Skeleton::bars(48, 6, 11, Theme::midnight()),
        );
    }

    public function testEveryRowInABlockIsIdentical(): void
    {
        // All rows share one phase, so they are the same band pattern.
        $rows = explode("\n", Skeleton::bars(30, 4, 8));

        self::assertSame($rows[0], $rows[1]);
        self::assertSame($rows[0], $rows[3]);
    }

    public function testTheBrightBandAdvancesWithThePhase(): void
    {
        // The bright/mid band sits at a DIFFERENT column as the phase grows. We
        // locate the band by the first non-base cell in each (Nocturne, plain) row.
        $atZero = self::bandStart(Skeleton::line(40, 0));
        $atFive = self::bandStart(Skeleton::line(40, 5));
        $atTen = self::bandStart(Skeleton::line(40, 10));

        self::assertNotSame($atZero, $atFive, 'the band moved between phase 0 and 5');
        self::assertNotSame($atFive, $atTen, 'the band moved between phase 5 and 10');
        // It travels rightward across the visible row as the phase climbs.
        self::assertLessThan($atFive, $atZero, 'the band sweeps to the right');
        self::assertLessThan($atTen, $atFive, 'the band keeps sweeping right');
    }

    public function testTheBandWrapsBackToTheStart(): void
    {
        // At a phase a full track-length apart the pattern repeats (the sweep
        // wraps): the row at phase P equals the row at phase P + (width + BAND).
        // width 20 + BAND 3 = 23, so phase 2 and phase 25 are the same frame.
        self::assertSame(Skeleton::line(20, 2), Skeleton::line(20, 25), 'the sweep is periodic over width + BAND');
        self::assertNotSame(Skeleton::line(20, 2), Skeleton::line(20, 3), 'adjacent phases differ');
    }

    public function testNocturneRendersNoSgr(): void
    {
        // The identity theme tints nothing — the skeleton carries zero escapes.
        $bars = Skeleton::bars(30, 3, 4, Theme::nocturne());

        self::assertStringNotContainsString("\e[", $bars, 'Nocturne is a plain (no-SGR) skeleton');
    }

    public function testDefaultThemeIsNocturneIdentity(): void
    {
        // Omitting the theme is identical to passing Nocturne.
        self::assertSame(
            Skeleton::bars(36, 4, 9, Theme::nocturne()),
            Skeleton::bars(36, 4, 9),
        );
    }

    public function testAColouredThemeTintsTheBand(): void
    {
        // Under a non-Nocturne theme the moving band cells carry an SGR accent;
        // the dim base glyphs do not.
        $row = Skeleton::line(40, 6, Theme::midnight());

        self::assertStringContainsString("\e[", $row, 'the band is accent-wrapped under a colour theme');
        self::assertStringContainsString('░', $row, 'the dim base glyph is still present, un-tinted');
    }

    public function testTheBaseGlyphFillsTheNonBandCells(): void
    {
        // A wide row at phase 0 (band at the far left) is mostly the dim base.
        $row = Skeleton::line(40, 0);

        self::assertGreaterThan(30, substr_count($row, '░'), 'the bulk of the row is the dim base glyph');
    }

    /** The 0-based column of the first non-base (band) cell in a plain row. */
    private static function bandStart(string $row): int
    {
        $cells = mb_str_split($row);
        foreach ($cells as $i => $cell) {
            if ($cell !== '░') {
                return $i;
            }
        }

        return -1; // no band cell visible (shouldn't happen in these tests)
    }
}

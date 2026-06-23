<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\MetricsOverlay;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

final class MetricsOverlayTest extends TestCase
{
    /** A base "screen" of $rows identical, easily-recognisable lines. */
    private function base(int $rows = 24, int $cols = 80): string
    {
        // Each row is its index followed by dashes out to $cols cells, so a
        // surviving tail is obvious and a corrupted row is easy to spot.
        $lines = [];
        for ($i = 0; $i < $rows; $i++) {
            $lines[] = Width::padRight('row' . $i . '-', $cols, '-');
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function lines(): array
    {
        return ['Mem    3.2 / 4.1 MB', 'Route  Browse', 'Theme  Nocturne'];
    }

    public function testRendersTheGivenMetricLines(): void
    {
        $out = MetricsOverlay::render($this->base(), $this->lines(), 80, 24);

        self::assertStringContainsString('Mem    3.2 / 4.1 MB', $out);
        self::assertStringContainsString('Route  Browse', $out);
        self::assertStringContainsString('Theme  Nocturne', $out);
    }

    public function testDrawsABorderedBox(): void
    {
        $out = MetricsOverlay::render($this->base(), $this->lines(), 80, 24);

        // The corners + edges of the frame are present.
        self::assertStringContainsString('┌', $out);
        self::assertStringContainsString('┐', $out);
        self::assertStringContainsString('└', $out);
        self::assertStringContainsString('┘', $out);
        self::assertStringContainsString('│', $out);
    }

    public function testTheBoxSitsInTheTopLeft(): void
    {
        $out = MetricsOverlay::render($this->base(), $this->lines(), 80, 24);
        $rows = explode("\n", $out);

        // Row 0 starts with the top border; the first content row starts with a
        // vertical edge then the first label.
        self::assertStringStartsWith('┌', $rows[0]);
        self::assertStringStartsWith('│ Mem', $rows[1]);
        self::assertStringStartsWith('└', $rows[4], 'the bottom border closes the box');
    }

    public function testLeavesTheRowsBelowTheBoxUntouched(): void
    {
        $base = $this->base();
        $out = MetricsOverlay::render($base, $this->lines(), 80, 24);

        $baseRows = explode("\n", $base);
        $outRows = explode("\n", $out);

        // The box is 3 lines + 2 borders = 5 rows; everything from row 5 down is
        // byte-identical to the base.
        for ($i = 5; $i < count($baseRows); $i++) {
            self::assertSame($baseRows[$i], $outRows[$i], "row {$i} (below the box) is untouched");
        }
    }

    public function testPreservesTheRightHandTailOfOverlaidRows(): void
    {
        $out = MetricsOverlay::render($this->base(), $this->lines(), 80, 24);
        $rows = explode("\n", $out);

        // The base rows are 80 dashes wide; the small box only covers the leading
        // cells, so each overlaid row keeps its dashed tail and is still 80 cells.
        foreach ([0, 1, 2, 3, 4] as $i) {
            self::assertSame(80, Width::string($rows[$i]), "overlaid row {$i} is still full width");
            self::assertStringEndsWith('-', $rows[$i], "the dashed tail of row {$i} survives");
        }
    }

    public function testTheLineCountIsUnchanged(): void
    {
        $base = $this->base(24);
        $out = MetricsOverlay::render($base, $this->lines(), 80, 24);

        self::assertSame(
            substr_count($base, "\n"),
            substr_count($out, "\n"),
            'compositing the HUD does not add or drop any lines',
        );
    }

    /**
     * Every overlaid row stays ANSI-safe: the visible width is unchanged so the
     * box never corrupts the terminal grid (tested across themes + base widths).
     *
     * @param int<1, max> $cols
     */
    #[DataProvider('themedWidths')]
    public function testEveryOverlaidRowKeepsItsWidth(?Theme $theme, int $cols): void
    {
        $base = $this->base(24, $cols);
        $out = MetricsOverlay::render($base, $this->lines(), $cols, 24, $theme);
        $rows = explode("\n", $out);

        foreach ($rows as $i => $row) {
            self::assertSame($cols, Width::string($row), "row {$i} is exactly {$cols} cells");
        }
    }

    /** @return iterable<string, array{?Theme, int}> */
    public static function themedWidths(): iterable
    {
        yield 'nocturne standard' => [Theme::nocturne(), 80];
        yield 'nocturne wide' => [Theme::nocturne(), 120];
        yield 'midnight standard' => [Theme::midnight(), 80];
        yield 'no theme' => [null, 80];
    }

    public function testNocturneRendersNoSgr(): void
    {
        // The identity theme tints nothing — the box border carries zero SGR.
        $out = MetricsOverlay::render($this->base(), $this->lines(), 80, 24, Theme::nocturne());

        self::assertStringNotContainsString("\e[", $out, 'Nocturne is a plain (no-SGR) HUD');
    }

    public function testDefaultThemeIsNocturneIdentity(): void
    {
        $out = MetricsOverlay::render($this->base(), $this->lines(), 80, 24);

        self::assertStringNotContainsString("\e[", $out, 'omitting the theme matches Nocturne (no SGR)');
    }

    public function testAColouredThemeTintsTheBox(): void
    {
        // Under Midnight the border (brand accent) is wrapped in SGR; the visible
        // width is still exactly the terminal width (the SGR does not count).
        $out = MetricsOverlay::render($this->base(), $this->lines(), 80, 24, Theme::midnight());

        self::assertStringContainsString("\e[", $out, 'a coloured theme tints the box border');
        // The accent wraps the top-left corner glyph.
        self::assertMatchesRegularExpression('/\e\[[0-9;]*m┌/', $out, 'the corner is accent-wrapped');
    }

    public function testATooNarrowTerminalRendersTheBaseUnchanged(): void
    {
        // 3 cols cannot fit even a 1-cell box (2 border + 2 pad = 4 > 3), so the
        // overlay is a no-op and the base passes through verbatim.
        $base = "abc\ndef";
        $out = MetricsOverlay::render($base, $this->lines(), 3, 24);

        self::assertSame($base, $out);
    }

    public function testAShortBaseStillGetsTheFullBox(): void
    {
        // A base with fewer lines than the box: the box rows are appended (the
        // overlay never drops a box row), and the visible box content is intact.
        $out = MetricsOverlay::render('only one line', $this->lines(), 80, 24);
        $rows = explode("\n", $out);

        self::assertGreaterThanOrEqual(5, count($rows), 'the whole box is laid out');
        self::assertStringContainsString('Mem', $out);
        self::assertStringContainsString('Theme  Nocturne', $out);
    }

    public function testTheBoxNeverDrawsBelowTheTerminalsLastRow(): void
    {
        // The 3 metric lines need a 5-row box, but the terminal is only 2 rows
        // tall: only the first 2 box rows are drawn (the rest are clipped), and
        // the result still has exactly the base's 2 rows.
        $base = $this->base(2, 80);
        $out = MetricsOverlay::render($base, $this->lines(), 80, 2);
        $rows = explode("\n", $out);

        self::assertCount(2, $rows, 'no box rows are appended past the terminal height');
        self::assertStringStartsWith('┌', $rows[0], 'the top border draws on row 0');
        self::assertStringStartsWith('│ Mem', $rows[1], 'the first content row draws on row 1');
        // Row 1 was the LAST drawable row; the bottom border (row 4 of the box)
        // is clipped — it never appears.
        self::assertStringNotContainsString('└', $out, 'the clipped bottom border is not drawn');
    }

    public function testALongMetricLineIsTruncatedToTheTerminalWidth(): void
    {
        // A label/value far wider than the terminal must not overflow the row.
        $long = 'Audio  music: ' . str_repeat('VeryLongTrackTitle ', 20);
        $out = MetricsOverlay::render($this->base(24, 40), [$long], 40, 24);
        $rows = explode("\n", $out);

        foreach ([0, 1, 2] as $i) {
            self::assertSame(40, Width::string($rows[$i]), "row {$i} is clamped to 40 cells");
        }
    }
}

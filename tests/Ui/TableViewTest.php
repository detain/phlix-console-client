<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\TableView;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

final class TableViewTest extends TestCase
{
    /** @return list<array{title: string, width: int, align?: string}> */
    private function columns(): array
    {
        return [
            ['title' => 'Album', 'width' => 0],
            ['title' => 'Artist', 'width' => 10],
            ['title' => 'Year', 'width' => 6, 'align' => 'right'],
        ];
    }

    /** @return list<list<string>> */
    private function rows(): array
    {
        return [
            ['Abbey Road', 'The Beatles', '1969'],
            ['Kind of Blue', 'Miles Davis', '1959'],
        ];
    }

    public function testHeaderSeparatorAndRowsAllRender(): void
    {
        $out = TableView::render($this->columns(), $this->rows(), 0, 60, 10);

        self::assertStringContainsString('Album', $out);
        self::assertStringContainsString('Artist', $out);
        self::assertStringContainsString('Year', $out);
        self::assertStringContainsString('─', $out, 'a separator rule is drawn');
        self::assertStringContainsString('Abbey Road', $out);
        self::assertStringContainsString('Kind of Blue', $out);
    }

    public function testEveryLineIsExactlyTotalWidthVisibleColumns(): void
    {
        $width = 50;
        $out = TableView::render($this->columns(), $this->rows(), 1, $width, 10);

        foreach (explode("\n", $out) as $line) {
            self::assertSame($width, Width::of($line), "line not exactly {$width} cells: [{$line}]");
        }
    }

    public function testCursorMarksOnlyTheSelectedRow(): void
    {
        $lines = explode("\n", TableView::render($this->columns(), $this->rows(), 1, 60, 10));

        // Lines 0 (header) and 1 (separator) carry no cursor; data rows follow.
        $dataLines = array_slice($lines, 2);
        self::assertCount(2, $dataLines);

        // Row 0 (Abbey Road) is NOT selected, row 1 (Kind of Blue) IS.
        self::assertStringStartsWith('  ', $dataLines[0], 'unselected row uses a blank gutter');
        self::assertStringContainsString('Abbey Road', $dataLines[0]);
        self::assertStringNotContainsString('▸', $dataLines[0]);

        self::assertStringStartsWith('▸ ', $dataLines[1], 'selected row uses the cursor gutter');
        self::assertStringContainsString('Kind of Blue', $dataLines[1]);
    }

    public function testHeaderAndSeparatorNeverCarryTheCursor(): void
    {
        $lines = explode("\n", TableView::render($this->columns(), $this->rows(), 0, 60, 10));

        self::assertStringNotContainsString('▸', $lines[0], 'header has no cursor');
        self::assertStringStartsWith('  ', $lines[0], 'header uses the blank gutter');
        self::assertStringNotContainsString('▸', $lines[1], 'separator has no cursor');
    }

    public function testFlexColumnFillsTheRemainingWidth(): void
    {
        // gutter(2) + flex + 1 + Artist(10) + 1 + Year(6) = 50 → flex = 30.
        $out = TableView::render($this->columns(), [['x', 'y', 'z']], 0, 50, 10);
        $header = explode("\n", $out)[0];

        // After the 2-col gutter, the Album (flex) cell occupies 30 cells:
        // 'Album' (5) + 25 spaces, then a single separator space before 'Artist'.
        self::assertStringStartsWith('  Album' . str_repeat(' ', 25) . ' Artist', $header);
    }

    public function testRightAlignPadsOnTheLeft(): void
    {
        // The Year column (width 6, right-aligned) is the LAST column, so the
        // data line ends with the right-justified '1969' (two leading spaces).
        $out = TableView::render($this->columns(), [['A', 'B', '1969']], 0, 50, 10);
        $dataLine = explode("\n", $out)[2];

        self::assertStringEndsWith('  1969', $dataLine, '1969 right-justified in its 6-wide column');
    }

    public function testRightAlignedHeaderTitleIsAlsoRightJustified(): void
    {
        // 'Year' (4) in a 6-wide right-aligned column → two leading spaces.
        $header = explode("\n", TableView::render($this->columns(), $this->rows(), 0, 50, 10))[0];

        self::assertStringContainsString('  Year', $header);
    }

    public function testOverlongCellIsTruncatedToItsColumnWidth(): void
    {
        // Artist column is 10 wide; a 20-char value must be cut to 10 visible cells.
        $rows = [['Album', 'Supercalifragilistic', '1999']];
        $out = TableView::render($this->columns(), $rows, 0, 60, 10);
        $dataLine = explode("\n", $out)[2];

        self::assertStringContainsString('Supercalif', $dataLine, 'first 10 chars kept');
        self::assertStringNotContainsString('Supercalifr', $dataLine, 'the 11th char is dropped');
    }

    public function testViewportWindowingKeepsTheSelectedRowVisible(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['Album ' . $i, 'Artist', (string) (2000 + $i)];
        }

        // Select row 18 with only a 5-row data viewport → it must be in the window.
        $out = TableView::render($this->columns(), $rows, 18, 60, 5);

        self::assertStringContainsString('Album 18', $out, 'the selected row scrolls into view');
        self::assertStringNotContainsString('Album 0 ', $out, 'far-off rows are scrolled away');

        // Header + separator + exactly 5 data rows = 7 lines.
        self::assertCount(7, explode("\n", $out));
    }

    public function testViewportShowsAllRowsWhenTheyFit(): void
    {
        // Two rows in a 10-row viewport → both shown, no windowing.
        $lines = explode("\n", TableView::render($this->columns(), $this->rows(), 0, 60, 10));

        self::assertCount(4, $lines, 'header + separator + 2 data rows');
    }

    public function testEmptyRowsRenderJustHeaderAndSeparator(): void
    {
        $out = TableView::render($this->columns(), [], 0, 60, 10);
        $lines = explode("\n", $out);

        self::assertCount(2, $lines, 'only the header and separator with no rows');
        self::assertStringContainsString('Album', $lines[0]);
        self::assertStringContainsString('─', $lines[1]);
    }

    public function testOutputContainsNoEscapeOrAnsiBytes(): void
    {
        $out = TableView::render($this->columns(), $this->rows(), 1, 60, 10);

        self::assertFalse(strpos($out, "\e"), 'no ESC byte anywhere in the output');
        self::assertFalse(strpos($out, "\x1b"), 'no ANSI control byte anywhere in the output');
    }

    public function testAllFixedColumnsWithNoFlexStillRenderExactWidth(): void
    {
        $columns = [
            ['title' => '#', 'width' => 4, 'align' => 'right'],
            ['title' => 'Title', 'width' => 20],
            ['title' => 'Duration', 'width' => 10, 'align' => 'right'],
        ];
        $rows = [['1', 'Come Together', '4:19']];

        $out = TableView::render($columns, $rows, 0, 60, 10);

        foreach (explode("\n", $out) as $line) {
            self::assertSame(60, Width::of($line), "non-flex layout still pads to width: [{$line}]");
        }
        self::assertStringContainsString('Come Together', $out);
        self::assertStringContainsString('4:19', $out);
    }

    public function testNarrowWidthStillProducesExactlySizedLines(): void
    {
        // A very narrow total width must not break the per-line width invariant.
        $out = TableView::render($this->columns(), $this->rows(), 0, 12, 5);

        foreach (explode("\n", $out) as $line) {
            self::assertSame(12, Width::of($line), "narrow line not 12 cells: [{$line}]");
        }
    }

    public function testShortRowTreatsMissingCellsAsEmpty(): void
    {
        // A row with fewer cells than columns must not error; missing cells blank.
        $out = TableView::render($this->columns(), [['Only Album']], 0, 50, 10);

        $dataLine = explode("\n", $out)[2];
        self::assertSame(50, Width::of($dataLine));
        self::assertStringContainsString('Only Album', $dataLine);
    }
}

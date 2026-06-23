<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Table;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * The thin sugar-table adapter: borderless, width-exact, reverse-video selection,
 * and a viewport that keeps the selected row on screen.
 */
final class TableTest extends TestCase
{
    private const COLUMNS = [
        ['title' => 'Name', 'width' => 0],                    // flex
        ['title' => 'Qty', 'width' => 5, 'align' => 'right'],
    ];

    /** @return list<list<string>> $n rows: ["item K", "K"] */
    private static function rows(int $n): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['item ' . $i, (string) $i];
        }

        return $rows;
    }

    private static function lines(string $out): array
    {
        return explode("\n", $out);
    }

    private static function hasReverse(string $line): bool
    {
        return preg_match('/\e\[(?:[0-9;]*;)?7(?:;[0-9;]*)?m/', $line) === 1;
    }

    public function testRendersBorderlessHeaderRuleAndRows(): void
    {
        $out = Table::render(self::COLUMNS, self::rows(3), 0, 30, 10);
        $stripped = Ansi::strip($out);

        self::assertStringNotContainsString('│', $stripped);          // no box border
        self::assertStringContainsString('Name', self::lines($stripped)[0]); // header first
        self::assertSame(str_repeat('─', 30), self::lines($stripped)[1]);     // rule second
        self::assertStringContainsString('item 0', $stripped);
    }

    public function testEveryLineIsExactlyTheRequestedWidth(): void
    {
        foreach ([16, 24, 40] as $w) {
            foreach (self::lines(Table::render(self::COLUMNS, self::rows(4), 1, $w, 10)) as $i => $line) {
                self::assertSame($w, Width::string($line), "line {$i} at width {$w}");
            }
        }
    }

    public function testSelectedRowIsReverseVideoAndOthersAreNot(): void
    {
        $out = self::lines(Table::render(self::COLUMNS, self::rows(3), 1, 30, 10));
        // header(0), rule(1), data 0 (2), data 1 = selected (3), data 2 (4)
        self::assertTrue(self::hasReverse($out[3]), 'selected row reversed');
        self::assertFalse(self::hasReverse($out[2]), 'row above not reversed');
        self::assertFalse(self::hasReverse($out[4]), 'row below not reversed');
    }

    public function testViewportWindowsTheSelectedRowIntoView(): void
    {
        // 50 rows, a 5-row data viewport, selection near the end: the selected
        // row must appear in the rendered window (the scroll follows it).
        $out = Ansi::strip(Table::render(self::COLUMNS, self::rows(50), 47, 30, 5));
        self::assertStringContainsString('item 47', $out, 'the selected row is scrolled into view');
        self::assertStringNotContainsString('item 0', $out, 'far-off rows are not rendered');
    }

    public function testFlexColumnTruncatesRatherThanOverflowing(): void
    {
        $rows = [['a really very long item name that overflows', '1']];
        $out = Ansi::strip(Table::render(self::COLUMNS, $rows, 0, 20, 10));

        foreach (self::lines(Table::render(self::COLUMNS, $rows, 0, 20, 10)) as $line) {
            self::assertSame(20, Width::string($line));
        }
        self::assertStringNotContainsString('overflows', $out);
    }

    public function testEmptyRowsRenderHeaderWithoutError(): void
    {
        $out = Table::render(self::COLUMNS, [], 0, 30, 10);
        self::assertStringContainsString('Name', Ansi::strip($out));
        self::assertFalse(self::hasReverse($out), 'nothing is selected when there are no rows');
    }
}

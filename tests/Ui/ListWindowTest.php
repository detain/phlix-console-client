<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\ListWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CU-047: the window math the ▶-cursor lists and windowed tables share,
 * tested directly (the behavioral conviction lives in
 * tests/Screen/ListCursorViewportTest). Same law Table::scrollY() has
 * enforced since M-CONSOLE-75 — single-sourced here so both families move
 * together or not at all.
 */
final class ListWindowTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function provideOffsets(): array
    {
        return [
            'empty list' => [0, 0, 10, 0],
            'fits entirely' => [5, 2, 14, 0],
            'exact viewport boundary' => [14, 13, 14, 0],
            'top of a long list keeps zero' => [40, 3, 14, 0],
            'mid list centres the cursor' => [40, 20, 14, 14],
            'last page clamps at count minus viewport' => [40, 39, 14, 26],
            'one-row viewport follows the cursor' => [40, 5, 1, 5],
            'degenerate zero viewport floors to one' => [40, 39, 0, 39],
            'degenerate negative viewport floors to one' => [40, 7, -5, 7],
            'selection beyond the end clamps to last page' => [40, 99, 14, 26],
        ];
    }

    #[DataProvider('provideOffsets')]
    public function testTheOffsetKeepsTheCursorInsideTheWindow(int $count, int $selected, int $viewport, int $expected): void
    {
        self::assertSame($expected, ListWindow::offset($count, $selected, $viewport));
    }

    /**
     * The law in one sentence: for every reachable selection the returned
     * window contains the cursor — no selection may fall outside, which is
     * exactly what the clipped lists used to guarantee.
     */
    public function testEverySelectionStaysInsideItsWindow(): void
    {
        foreach ([1, 2, 7, 8, 15, 40] as $count) {
            foreach ([1, 2, 3, 14, 39] as $viewport) {
                for ($selected = 0; $selected < $count; $selected++) {
                    $offset = ListWindow::offset($count, $selected, $viewport);

                    self::assertGreaterThanOrEqual(0, $offset);
                    self::assertLessThanOrEqual(max(0, $count - 1), $offset);
                    self::assertGreaterThanOrEqual(
                        $offset,
                        $selected,
                        "count={$count} viewport={$viewport} selection={$selected} fell behind the window"
                    );
                    self::assertLessThan(
                        $offset + max(1, $viewport),
                        $selected,
                        "count={$count} viewport={$viewport} selection={$selected} walked past the window"
                    );
                }
            }
        }
    }
}

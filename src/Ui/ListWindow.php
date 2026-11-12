<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

/**
 * The window law behind every scrolling list in the app (CU-047).
 *
 * A ▶-cursor list that renders ALL its rows lets the content leaf clip the
 * tail: past the viewport the cursor walks into invisible rows, pressing
 * Down does nothing visible, and the rest of the list is unreachable. The
 * cure is to window the list around the selection — the exact formula
 * {@see Table::scrollY()} has used for windowed tables since M-CONSOLE-75,
 * single-sourced here so plain lists and tables cannot drift apart.
 */
final class ListWindow
{
    /**
     * Index of the first visible row so $selected always stays on screen,
     * centring it when the data overflows the viewport, never scrolling past
     * the last page.
     *
     * @param int $count    total rows in the list
     * @param int $selected index of the selected row
     * @param int $viewport visible rows; floored to 1 — a degenerate layout
     *                      must still show the cursor's row, never hide all
     */
    public static function offset(int $count, int $selected, int $viewport): int
    {
        $viewport = max(1, $viewport);

        if ($count <= $viewport) {
            return 0;
        }

        return max(0, min($selected - intdiv($viewport - 1, 2), $count - $viewport));
    }
}

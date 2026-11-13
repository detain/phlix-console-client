<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\ListWindow;

/**
 * The viewport window over a private array $items list — the 5 ▶-cursor list
 * screens (Collections/Favorites/Playlists/Recommendations/WatchHistory) all
 * used to render EVERY row and let the content leaf clip the tail, so the
 * cursor walked into invisible rows once the page outgrew the panel (CU-047).
 *
 * A screen `use`s this trait when it keeps private int $selectedIndex, a
 * private list $items and a private int $rows (the terminal height the
 * {@see ResizesTo} seam maintains). The body asks for the window FIRST and
 * renders only the visible slice — the cursor row is then structurally
 * inside what the panel can show. Window math lives in {@see ListWindow};
 * the behavioral law is pinned by tests/Screen/ListCursorViewportTest.
 */
trait WindowedList
{
    /**
     * The window of list entries that fits the content panel, and its offset
     * into the full list.
     *
     * @template T
     * @param list<T> $rows one entry per item (full list)
     * @param int $hiddenRows body lines the layout spends outside the list:
     *                        the "\n\n" lead costs 2; the delete-prompt
     *                        branch spends 4 (lead + prompt + cancel hint)
     * @param int $rowsPerItem          screen lines each entry renders (poster
     *                                cards print a title + meta line = 2)
     * @return array{list<T>, int} visible slice + its offset in the full list
     */
    private function listWindow(array $rows, int $hiddenRows = 2, int $rowsPerItem = 1): array
    {
        $viewport = max(1, intdiv(Chrome::bodyHeight($this->rows) - $hiddenRows, $rowsPerItem));
        $offset = ListWindow::offset(count($rows), $this->selectedIndex, $viewport);

        return [array_slice($rows, $offset, $viewport), $offset];
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Width;

/**
 * A hand-rolled, plain-text aligned table for the app shell. Unlike sugar-table
 * it emits ZERO ANSI: {@see Chrome} composes the body with sugar-boxer, whose
 * content placement is ANSI-width-UNAWARE (escape bytes consume cells) and whose
 * inner width is exactly `cols - 4`. Any ANSI in the body would get clipped
 * mid-escape (eating the rightmost columns, even bleeding colour past a row), so
 * the whole table — header, separator, and rows — is plain text and every line
 * is padded to exactly the caller's {@see $totalWidth} visible cells.
 *
 * Selection is shown with a plain-text cursor gutter (`▸ ` selected / `  ` not)
 * rather than reverse-video, for the same ANSI-safety reason. The renderer is
 * static and stateless (like {@see Sidebar} / {@see LetterRail}); the owning
 * screen passes the columns, the rows, the selected index, the available width,
 * and the data-row viewport height each render.
 */
final class TableView
{
    /** Width of the leading cursor gutter (`▸ ` / `  `). */
    private const GUTTER = 2;

    /**
     * Render the table as `\n`-joined lines, each exactly $totalWidth cells:
     * a header line, a separator rule, then the data rows windowed to
     * $viewportRows around $selected. With no rows only the header + separator
     * are returned (the caller renders its own empty message).
     *
     * @param list<array{title: string, width: int, align?: string}> $columns each column's
     *        header title, fixed width (0 = the single flex column, filling the remainder), and
     *        optional 'right' alignment (default left)
     * @param list<list<string>> $rows each row = one cell string per column, in column order
     * @param int $selected index into $rows of the selected (cursor) row
     * @param int $totalWidth the available content width (caller passes `cols - 4`)
     * @param int $viewportRows the number of DATA rows to show (header + separator are extra)
     */
    public static function render(array $columns, array $rows, int $selected, int $totalWidth, int $viewportRows): string
    {
        $totalWidth = max(1, $totalWidth);
        $widths = self::resolveWidths($columns, $totalWidth);

        $lines = [
            self::row('  ', self::cells($columns, $widths, self::titles($columns)), $totalWidth),
            self::padLine(str_repeat('─', $totalWidth), $totalWidth),
        ];

        $count = count($rows);
        if ($count > 0) {
            foreach (self::window($count, $selected, max(1, $viewportRows)) as $i) {
                $gutter = $i === $selected ? '▸ ' : '  ';
                $lines[] = self::row($gutter, self::cells($columns, $widths, $rows[$i]), $totalWidth);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve each column's effective width: fixed widths verbatim, and the one
     * flex column (width 0) gets whatever room is left after the gutter, the
     * fixed columns, and the inter-column separators (floored at 4).
     *
     * @param list<array{title: string, width: int, align?: string}> $columns
     * @return list<int>
     */
    private static function resolveWidths(array $columns, int $totalWidth): array
    {
        $separators = max(0, count($columns) - 1);
        $fixed = 0;
        foreach ($columns as $col) {
            $fixed += max(0, $col['width']);
        }
        $flex = max(4, $totalWidth - self::GUTTER - $fixed - $separators);

        $widths = [];
        foreach ($columns as $col) {
            $widths[] = $col['width'] === 0 ? $flex : $col['width'];
        }

        return $widths;
    }

    /**
     * Truncate then align each cell to its column width (right-align left-pads,
     * default left-pads-right), returning the cell strings joined by a single
     * space. A short row (fewer cells than columns) treats the missing cells as
     * empty.
     *
     * @param list<array{title: string, width: int, align?: string}> $columns
     * @param list<int> $widths
     * @param list<string> $values
     */
    private static function cells(array $columns, array $widths, array $values): string
    {
        $out = [];
        foreach ($columns as $i => $col) {
            $width = $widths[$i];
            $value = Width::truncate($values[$i] ?? '', $width);
            $out[] = ($col['align'] ?? 'left') === 'right'
                ? Width::padLeft($value, $width)
                : Width::padRight($value, $width);
        }

        return implode(' ', $out);
    }

    /**
     * The column titles, in order.
     *
     * @param list<array{title: string, width: int, align?: string}> $columns
     * @return list<string>
     */
    private static function titles(array $columns): array
    {
        return array_map(static fn (array $col): string => $col['title'], $columns);
    }

    /** A gutter + cells line, padded to exactly $totalWidth visible cells. */
    private static function row(string $gutter, string $cells, int $totalWidth): string
    {
        return self::padLine($gutter . $cells, $totalWidth);
    }

    /** Pad (or truncate) a line to exactly $totalWidth visible cells. */
    private static function padLine(string $line, int $totalWidth): string
    {
        return Width::padRight(Width::truncate($line, $totalWidth), $totalWidth);
    }

    /**
     * The data-row indices to show for a $viewportRows-tall window, scrolled so
     * the selected row stays on screen (mirrors {@see Sidebar::window()}).
     *
     * @return list<int>
     */
    private static function window(int $count, int $selected, int $viewportRows): array
    {
        $start = 0;
        if ($count > $viewportRows) {
            $start = max(0, min($selected - intdiv($viewportRows - 1, 2), $count - $viewportRows));
        }
        $end = min($count, $start + $viewportRows);

        return range($start, $end - 1);
    }
}

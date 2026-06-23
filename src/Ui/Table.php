<?php

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Table\Column;
use SugarCraft\Table\ColumnWidth;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table as SugarTable;

/**
 * A thin adapter from the app's column spec to a borderless {@see SugarTable}.
 *
 * The hand-rolled plain-text TableView this replaces existed only because
 * sugar-table double-bordered inside the {@see Chrome} shell, had non-deterministic
 * width, and wasn't ANSI-safe for selection. Those are fixed upstream now
 * (borderless mode, width-exact Flex columns, reverse-video selection that
 * sugar-boxer's ANSI-aware placement keeps from bleeding), so this just maps the
 * caller's spec onto the library and lets it do all the rendering — no padding,
 * truncation, or windowing logic of our own.
 *
 * Borderless so it nests in Chrome's content box without a double border;
 * width-exact so the table is precisely the given content width; the selected row
 * renders real reverse-video spanning the full row.
 */
final class Table
{
    /**
     * Render the table to `\n`-joined lines: a header, a rule, then the data rows
     * windowed to $viewportRows around $selected (the library handles the window
     * and the reverse-video highlight).
     *
     * @param list<array{title: string, width: int, align?: string}> $columns each column's
     *        header title, fixed width (0 = the single flex column, filling the remainder), and
     *        optional 'right' alignment (default left)
     * @param list<list<string>> $rows each row = one cell string per column, in column order
     * @param int $selected index into $rows of the selected (cursor) row
     * @param int $totalWidth the available content width (caller passes `cols - 4`)
     * @param int $viewportRows the number of DATA rows to show (header + rule are extra)
     */
    public static function render(array $columns, array $rows, int $selected, int $totalWidth, int $viewportRows): string
    {
        $count = count($rows);
        $viewport = max(1, $viewportRows);

        return SugarTable::withColumns(self::columns($columns))
            ->withRows(self::rows($columns, $rows))
            ->withBorderless()
            ->withWidth(max(1, $totalWidth))
            ->withCellPadding(0)
            ->withSelectable($count > 0)
            ->withSelectedIndex($selected)
            ->withViewportHeight($viewport)
            ->withScrollY(self::scrollY($count, $selected, $viewport))
            ->View();
    }

    /**
     * @param list<array{title: string, width: int, align?: string}> $columns
     * @return list<Column>
     */
    private static function columns(array $columns): array
    {
        $out = [];
        foreach ($columns as $i => $col) {
            $column = Column::new('c' . $i, $col['title'], max(0, $col['width']))
                // width 0 = the flex column that fills the remaining width exactly.
                ->withColumnWidth($col['width'] === 0 ? ColumnWidth::Flex : ColumnWidth::Fixed)
                // sugar-table left-aligns when alignLeft is true; our spec marks
                // right-aligned columns with align => 'right'.
                ->withAlignLeft(($col['align'] ?? 'left') !== 'right');
            $out[] = $column;
        }

        return $out;
    }

    /**
     * @param list<array{title: string, width: int, align?: string}> $columns
     * @param list<list<string>> $rows
     * @return list<Row>
     */
    private static function rows(array $columns, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $data = [];
            foreach ($columns as $i => $col) {
                $data['c' . $i] = $row[$i] ?? '';
            }
            $out[] = Row::new(RowData::from($data));
        }

        return $out;
    }

    /**
     * First visible row so the selected row stays on screen, centring it when the
     * data overflows the viewport (mirrors the prior windowing behaviour).
     */
    private static function scrollY(int $count, int $selected, int $viewport): int
    {
        if ($count <= $viewport) {
            return 0;
        }

        return max(0, min($selected - intdiv($viewport - 1, 2), $count - $viewport));
    }
}

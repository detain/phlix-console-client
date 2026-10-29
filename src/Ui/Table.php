<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Width;
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
 * sugar-panels's ANSI-aware placement keeps from bleeding), so this just maps the
 * caller's spec onto the library and lets it do all the rendering — no padding,
 * truncation, or windowing logic of our own.
 *
 * Borderless so it nests in Chrome's content box without a double border;
 * width-exact so the table is precisely the given content width; the selected row
 * renders real reverse-video spanning the full row.
 *
 * When the caller's width cannot pay for the declared columns, the layout
 * degrades GRACEFULLY instead of silently deleting its primary column: named
 * (fixed-width) columns are dropped right-to-left until the `width: 0` primary
 * has room to show its own header — {@see reservePrimaryColumns()}.
 */
final class Table
{
    /**
     * Cells sugar-table reserves between adjacent columns. Measured from the
     * vendored width solver (it deducts `count - 1` gap cells before dividing
     * the remainder among flex columns), so the budget law below spends the
     * same currency the renderer will.
     */
    private const COLUMN_GAP = 1;

    /**
     * The floor the primary column gets even when its header is shorter (an
     * empty or tiny title must not re-open the zero-width vanishing act this
     * cures — four cells is the narrowest honest cell: a cluster plus the
     * truncation marker).
     */
    private const PRIMARY_MIN_WIDTH = 4;

    /**
     * Render the table to `\n`-joined lines: a header, a rule, then the data rows
     * windowed to $viewportRows around $selected (the library handles the window
     * and the reverse-video highlight).
     *
     * @param list<array{title: string, width: int, align?: string}> $columns each column's
     *        header title, fixed width (0 = the single flex column, filling the remainder), and
     *        optional 'right' alignment (default left). When the budget cannot fit the table,
     *        named columns drop right-to-left; the primary (width 0) never vanishes silently.
     * @param list<list<string>> $rows each row = one cell string per column, in column order
     * @param int $selected index into $rows of the selected (cursor) row
     * @param int $totalWidth the available content width (caller passes `cols - 4`)
     * @param int $viewportRows the number of DATA rows to show (header + rule are extra)
     * @param bool $selectable whether a row is highlighted (reverse-video); pass false for a
     *        read-only table (e.g. a stats panel) so no row is shown as a cursor. Default true.
     */
    public static function render(array $columns, array $rows, int $selected, int $totalWidth, int $viewportRows, bool $selectable = true): string
    {
        $count = count($rows);
        $viewport = max(1, $viewportRows);
        $kept = self::reservePrimaryColumns($columns, $totalWidth);

        return SugarTable::fromColumns(self::columns($kept))
            ->withRows(self::rows($kept, $rows))
            ->withBorderless()
            ->withWidth(max(1, $totalWidth))
            ->withCellPadding(0)
            ->withSelectable($selectable && $count > 0)
            ->withSelectedIndex(max(0, $selected))
            ->withViewportHeight($viewport)
            ->withScrollY(self::scrollY($count, $selected, $viewport))
            ->View();
    }

    /**
     * Degrade a table whose declared columns do not fit (M-CONSOLE-46).
     *
     * The `width: 0` column is the table's primary — sugar-table solves it as
     * "whatever remains after every named column and its gap cell", so on a
     * narrow terminal the remainder went NEGATIVE and the primary silently
     * vanished: AdminUsersScreen's Username column rendered as a 4-cell stub
     * at 80 terminal cols (76 content) and as zero cells — deleted, header
     * included — at 76 terminal cols. Measured at HEAD before this cure.
     *
     * The law: declaration order IS priority. Every screen declares its
     * identifier first and its trailing metadata last, so when the budget
     * cannot fit the table the RIGHTMOST named column yields, one at a time,
     * until every kept column can show at least its own header (the primary
     * additionally floored at {@see PRIMARY_MIN_WIDTH}). The primary is never
     * dropped; at worst it keeps a sugar-table `…`-marked truncation, which
     * is honest, instead of silent deletion. A table that fits — every
     * wide-terminal render — passes through untouched, byte-identical, and so
     * does a table with no primary at all (nothing to protect).
     *
     * @param list<array{title: string, width: int, align?: string}> $columns
     * @return array<int, array{title: string, width: int, align?: string}> kept
     *         columns with their ORIGINAL keys, so {@see rows()} still reads
     *         cell `$i` of each row for the kept column `$i`
     */
    private static function reservePrimaryColumns(array $columns, int $totalWidth): array
    {
        $hasPrimary = false;
        foreach ($columns as $column) {
            if ($column['width'] === 0) {
                $hasPrimary = true;
                break;
            }
        }
        if (!$hasPrimary) {
            return $columns;
        }

        $kept = $columns;
        while (self::minimumWidth($kept) > $totalWidth) {
            $rightmostNamed = null;
            foreach ($kept as $index => $column) {
                if ($column['width'] !== 0) {
                    $rightmostNamed = $index;
                }
            }
            if ($rightmostNamed === null) {
                break;
            }
            unset($kept[$rightmostNamed]);
        }

        return $kept;
    }

    /**
     * The narrowest width at which every column can show its own header: each
     * named column's declared width, each primary's header width (floored),
     * plus sugar-table's gap cell between every adjacent pair.
     *
     * @param array<int, array{title: string, width: int, align?: string}> $columns
     */
    private static function minimumWidth(array $columns): int
    {
        $width = max(0, count($columns) - 1) * self::COLUMN_GAP;
        foreach ($columns as $column) {
            $width += $column['width'] === 0
                ? max(self::PRIMARY_MIN_WIDTH, Width::string($column['title']))
                : max(0, $column['width']);
        }

        return $width;
    }

    /**
     * @param array<int, array{title: string, width: int, align?: string}> $columns
     *        original keys allowed (the drop ladder leaves gaps); each entry
     *        appends to the true list sugar-table is handed
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
     * @param array<int, array{title: string, width: int, align?: string}> $columns
     *        original keys allowed; each row's cell at index `$i` feeds the
     *        kept column `$i`
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

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Width;

/**
 * The toggleable diagnostic HUD: a small bordered box of `Label  value` lines
 * drawn in the TOP-LEFT corner, composited over whatever screen is showing
 * (like the toast / palette overlays). Purely a render — no mutable state; the
 * App owns the toggle flag and supplies the metric lines.
 *
 *   ┌────────────────────────┐
 *   │ Mem    3.2 / 4.1 MB     │
 *   │ Term   80×24           │
 *   │ Route  Browse          │
 *   └────────────────────────┘
 *
 * Compositing is a precise, non-corrupting line-replace: $base is split on
 * `\n`, and for each of the box's rows the leading $boxWidth cells of the
 * underlying line are replaced with the box content while the line's right-hand
 * TAIL is preserved via {@see Width::dropAnsi} (ANSI state carried through). The
 * box content is itself ANSI-safe — every row is {@see Width::truncateAnsi}-
 * truncated and {@see Width::padRight}-padded to exactly $boxWidth cells. Rows
 * below the box are left completely untouched, so $base's overall line count is
 * unchanged.
 *
 * The border + the leading `│ ` of each content row may be tinted with the
 * theme's brand accent (Nocturne is plain — a no-op, asserted zero-SGR); the
 * `Label  value` text stays plain.
 */
final class MetricsOverlay
{
    /** Box-drawing corners / edges for the frame. */
    private const TOP_LEFT = '┌';
    private const TOP_RIGHT = '┐';
    private const BOTTOM_LEFT = '└';
    private const BOTTOM_RIGHT = '┘';
    private const HORIZONTAL = '─';
    private const VERTICAL = '│';

    /** Cells of padding inside the vertical borders (one space each side). */
    private const INNER_PAD = 1;

    /**
     * Composite a metrics box of $lines onto the top-left of $base.
     *
     * @param list<string> $lines each a `Label  value` string (ANSI-stripped for width)
     */
    public static function render(string $base, array $lines, int $cols, int $rows, ?Theme $theme = null): string
    {
        $cols = max(1, $cols);
        $rows = max(1, $rows);

        // Widest content line bounds the inner width; the box never exceeds the
        // terminal (leaving 2 cells for the borders + the inner padding).
        $maxContent = $cols - 2 - (2 * self::INNER_PAD);
        if ($maxContent < 1) {
            // The terminal is too narrow for even a 1-cell box — render nothing.
            return $base;
        }

        $inner = 0;
        foreach ($lines as $line) {
            $inner = max($inner, Width::string($line));
        }
        $inner = max(1, min($inner, $maxContent));

        $accent = ($theme ?? Theme::nocturne())->brandStyle();

        // The box rows: top border, one row per metric line, bottom border.
        $box = [];
        $box[] = $accent->render(self::TOP_LEFT . str_repeat(self::HORIZONTAL, $inner + 2 * self::INNER_PAD) . self::TOP_RIGHT);
        foreach ($lines as $line) {
            $cell = Width::padRight(Width::truncateAnsi($line, $inner), $inner);
            $left = $accent->render(self::VERTICAL) . str_repeat(' ', self::INNER_PAD);
            $right = str_repeat(' ', self::INNER_PAD) . $accent->render(self::VERTICAL);
            $box[] = $left . $cell . $right;
        }
        $box[] = $accent->render(self::BOTTOM_LEFT . str_repeat(self::HORIZONTAL, $inner + 2 * self::INNER_PAD) . self::BOTTOM_RIGHT);

        // The exact display width every box row occupies (border + pad + inner).
        $boxWidth = $inner + 2 + 2 * self::INNER_PAD;

        return self::overlay($base, $box, $boxWidth, $rows);
    }

    /**
     * Replace the leading $boxWidth cells of the first count($box) lines of
     * $base with the box rows, preserving each underlying line's tail (and
     * appending missing lines if $base is shorter than the box). Lines beyond
     * the box are untouched; the line count never shrinks.
     *
     * @param list<string> $box each row exactly $boxWidth display cells
     */
    private static function overlay(string $base, array $box, int $boxWidth, int $rows): string
    {
        $lines = explode("\n", $base);
        $count = count($box);

        for ($i = 0; $i < $count; $i++) {
            // Never draw below the terminal's last row.
            if ($i >= $rows) {
                break;
            }
            $under = $lines[$i] ?? '';
            // Keep the underlying line's tail (everything past the box) with its
            // ANSI state intact; dropAnsi on a too-short line yields '' (no pad,
            // which is fine — the chrome already fills the row).
            $lines[$i] = $box[$i] . Width::dropAnsi($under, $boxWidth);
        }

        return implode("\n", $lines);
    }
}

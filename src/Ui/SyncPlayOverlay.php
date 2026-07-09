<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Width;

/**
 * The SyncPlay status overlay — a small bordered box showing the current
 * SyncPlay room state when the player is part of a group.
 *
 *   ┌─────────────────────────────┐
 *   │ ◉ Room: Movie Night  (3)  │ │
 *   │   Synced                   │ │
 *   └─────────────────────────────┘
 *
 * Composited in the TOP-RIGHT corner over the player frame, preserving the
 * underlying content's right-hand tail via Width::dropAnsi.
 */
final class SyncPlayOverlay
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
     * Composite a SyncPlay status box onto the top-right of $base.
     *
     * @param string      $base    The terminal screen to overlay onto
     * @param string|null $roomName Name of the SyncPlay room (null if not in a room)
     * @param int         $memberCount Number of members in the room
     * @param string      $syncStatus Status text: "Synced", "Syncing...", "Connecting..."
     * @param int         $cols    Terminal column count
     * @param int         $rows    Terminal row count
     */
    public static function render(
        string $base,
        ?string $roomName,
        int $memberCount,
        string $syncStatus,
        int $cols,
        int $rows,
        ?Theme $theme = null,
    ): string {
        // If not in a room, render nothing.
        if ($roomName === null) {
            return $base;
        }

        $cols = max(1, $cols);
        $rows = max(1, $rows);

        $accent = ($theme ?? Theme::nocturne())->brandStyle();

        // Build the content lines.
        $roomLine = "◉ {$roomName}  ({$memberCount})";
        $statusLine = "  {$syncStatus}";

        $lines = [$roomLine, $statusLine];

        // Widest content line bounds the inner width.
        $maxContent = $cols - 2 - (2 * self::INNER_PAD);
        if ($maxContent < 1) {
            return $base;
        }

        $inner = 0;
        foreach ($lines as $line) {
            $inner = max($inner, Width::string($line));
        }
        $inner = max(1, min($inner, $maxContent));

        // The box rows: top border, one row per content line, bottom border.
        $box = [];
        $box[] = $accent->render(self::TOP_LEFT . str_repeat(self::HORIZONTAL, $inner + 2 * self::INNER_PAD) . self::TOP_RIGHT);
        foreach ($lines as $line) {
            $cell = Width::padRight(Width::truncateAnsi($line, $inner), $inner);
            $left = $accent->render(self::VERTICAL) . str_repeat(' ', self::INNER_PAD);
            $right = str_repeat(' ', self::INNER_PAD) . $accent->render(self::VERTICAL);
            $box[] = $left . $cell . $right;
        }
        $box[] = $accent->render(self::BOTTOM_LEFT . str_repeat(self::HORIZONTAL, $inner + 2 * self::INNER_PAD) . self::BOTTOM_RIGHT);

        // Box width including borders and padding.
        $boxWidth = $inner + 2 + 2 * self::INNER_PAD;

        return self::overlayTopRight($base, $box, $boxWidth, $cols, $rows);
    }

    /**
     * Replace the trailing $boxWidth cells of the first count($box) lines of
     * $base with the box rows, preserving each underlying line's left-hand content
     * and appending missing lines if $base is shorter than the box.
     *
     * @param list<string> $box     Each row exactly $boxWidth display cells
     * @param int          $boxWidth Total width of the box in cells
     * @param int          $cols     Terminal columns
     * @param int          $rows     Terminal rows
     */
    private static function overlayTopRight(string $base, array $box, int $boxWidth, int $cols, int $rows): string
    {
        $lines = explode("\n", $base);
        $count = count($box);

        for ($i = 0; $i < $count; $i++) {
            if ($i >= $rows) {
                break;
            }
            $under = $lines[$i] ?? '';
            // Keep the underlying line's left content (everything before where the box starts),
            // using dropAnsi from the right to preserve ANSI state.
            $boxStartX = max(0, $cols - $boxWidth);
            $leftContent = Width::takeAnsi($under, $boxStartX);
            $lines[$i] = $leftContent . $box[$i];
        }

        return implode("\n", $lines);
    }
}

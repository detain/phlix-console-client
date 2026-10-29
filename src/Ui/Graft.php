<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Absolute cursor-move grafting of overlay boxes onto frames whose first
 * line is an opaque pixel-graphics blob (see {@see \Phlix\Console\Screen\LineAligned}).
 *
 * Line-splicing compositors (sugar-overlay canvases, the metrics HUD, the
 * toast layer) address the frame by "\n"-indexed lines. On a graphics frame
 * that index is a lie twice over: the blob is one logical line spanning many
 * terminal rows (and Width::truncateAnsi/dropAnsi only pass CSI+OSC through,
 * so slicing it would sever a DCS/APC payload mid-blob), and rows emitted
 * after it land wherever the image left the cursor — the same trap
 * {@see \Phlix\Console\Screen\PlayerScreen::graphicsChrome()} solves with
 * absolute moves. Grafting is that idiom generalized: each box row is written
 * as `cursorTo(row, col) . row-text` appended to the frame, so it lands on
 * its resolved SCREEN row regardless of where the blob's cursor ended up,
 * and the blob line is never touched.
 *
 * The anchors mirror sugar-overlay's {@see \SugarCraft\Overlay\Position} math
 * exactly (TOP-LEFT = 0,0; TOP-RIGHT = `bgWidth − boxWidth`; CENTER =
 * `floor((bg − fg) / 2)`), measured over the terminal's (cols × rows) canvas
 * — so a grafted box occupies the same cells its spliced twin would on a
 * plain frame. The backdrop dim is the deliberate difference: dimming an
 * image is not line-expressible, so the graft paints the box only.
 *
 * Grafted rows carry exactly their own cells (no erase): where the splice
 * would have kept the underlying row's tail, the graft leaves the image
 * painted — the honest overlay reading, and the same cell-coverage the box
 * has when it moves between frames (each tick re-emits the whole line).
 */
final class Graft
{
    private function __construct()
    {
    }

    /**
     * Append $boxLines to $frame, each written with an absolute move to
     * (0-based) screen ($row + i, $col). Never emits "\n", so the frame's
     * logical-line structure (and the blob inside it) survives untouched.
     *
     * @param list<string> $boxLines styled box rows
     */
    public static function at(string $frame, array $boxLines, int $row, int $col): string
    {
        $out = $frame;
        foreach ($boxLines as $i => $line) {
            $out .= Ansi::cursorTo($row + $i + 1, $col + 1) . $line;
        }

        return $out;
    }

    /**
     * Graft $box centered over a (cols × rows) canvas — the same placement
     * sugar-overlay's CENTER/CENTER composite resolves to on a plain frame of
     * that size (both offsets floor-divide the slack; negative slack clamps
     * to 0, matching Veil's anchor clamp).
     */
    public static function centered(string $frame, string $box, int $cols, int $rows): string
    {
        $boxLines = self::splitLines($box);
        $boxHeight = count($boxLines);
        $boxWidth = self::maxWidth($boxLines);

        return self::at(
            $frame,
            $boxLines,
            max(0, (int) floor(($rows - $boxHeight) / 2)),
            max(0, (int) floor(($cols - $boxWidth) / 2)),
        );
    }

    /**
     * Graft $box at the top-right of a $cols-wide canvas — the same anchor
     * {@see SyncPlayOverlay}'s row splice uses (`max(0, cols − boxWidth)`,
     * top rows).
     *
     * @param list<string> $box styled box rows
     */
    public static function topRight(string $frame, array $box, int $boxWidth, int $cols): string
    {
        return self::at($frame, $box, 0, max(0, $cols - $boxWidth));
    }

    /**
     * Split a string into lines the way sugar-overlay's measurement does:
     * explode on "\n", dropping the empty tail a final newline leaves.
     *
     * @return list<string>
     */
    public static function splitLines(string $text): array
    {
        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private static function maxWidth(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $max = max($max, Width::string($line));
        }

        return $max;
    }
}

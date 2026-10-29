<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Width;

/**
 * The client's single display-width truncator with a trailing ellipsis.
 *
 * Three admin screens each carried a byte-identical private helper counting
 * CODEPOINTS (mb_strlen/mb_substr) where the terminal bills CELLS: a CJK
 * title cut for a 10-cell budget rendered 19 columns wide and blew the
 * table row past its allotment, and mb_substr happily splits a ZWJ emoji
 * sequence or orphans a combining mark. This class is the one choke, built
 * on the vendor's grapheme + east-asian-width {@see Width} splitter — the
 * same helper Sidebar/DetailScreen/PlaylistsScreen already call directly
 * for their ellipsis-less budget lines.
 *
 * Contract: the result never exceeds $maxCells columns, and every cluster of
 * the input that survives the cut is whole. ANSI sequences are dropped from
 * the truncated branch (Width strips them); the fits branch returns the
 * input untouched, matching the family's original behavior at every site.
 */
final class Truncate
{
    /** The single-cell horizontal ellipsis the family appended by hand. */
    public const ELLIPSIS = '…';

    /**
     * Fit $text into at most $maxCells terminal cells, appending
     * {@see ELLIPSIS} when — and only when — a cut was needed.
     * $maxCells <= 0 yields '' (the budget itself vanished).
     */
    public static function withEllipsis(string $text, int $maxCells): string
    {
        if ($maxCells <= 0) {
            return '';
        }

        if (Width::string($text) <= $maxCells) {
            return $text;
        }

        $budget = $maxCells - Width::string(self::ELLIPSIS);

        return rtrim(Width::truncate($text, $budget)) . self::ELLIPSIS;
    }
}

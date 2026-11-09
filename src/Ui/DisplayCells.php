<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Width;

/**
 * Conservative cell accounting for emoji-presentation glyphs.
 *
 * The vendored {@see Width} scorer follows wcwidth: U+23F8 (⏸) and U+25B6 (▶)
 * score ONE cell — yet their Unicode Emoji_Presentation property is Yes, so
 * emoji-aware terminals (kitty, WezTerm, foot with an emoji font) render them
 * TWO cells wide. Any line our renderer pads/truncates to EXACTLY the terminal
 * width and that carries one of these glyphs can therefore occupy width+1
 * cells: on the last physical row a DECAWM wrap scrolls the screen a line,
 * sliding sixel/kitty pixel payloads under PlayerScreen's absolute chrome
 * [review console__tuispec #2 / CU-054].
 *
 * The scorer cannot be patched (vendored dep, vendor-patches policy), so this
 * is the client-side reservation: every glyph in {@see WIDE_GLYPHS} is billed
 * an extra cell, turning an under-count into a (visibly harmless) one-cell
 * over-count on 1-cell terminals. U+23CE (⏎, the hints' Enter key) is
 * Emoji_Presentation=No and needs no reservation; CJK and the thumbs-up
 * pair already score 2 in the vendor.
 *
 * This class is only for lines that ride the FULL terminal width. Rows inside
 * a bordered panel have slack (the panel's own placement clips them), so the
 * app's cursor prefixes and table cells stay on {@see Width} unchanged —
 * dispositions enumerated in the CU-054 register entry.
 */
final class DisplayCells
{
    /**
     * Codepoints the vendor scores as 1 cell but an emoji-aware terminal may
     * render as 2: the pause and play glyphs this client leads full-width
     * lines with. (U+23F8 ⏸, U+25B6 ▶.)
     */
    public const WIDE_GLYPHS = ['⏸', '▶'];

    /** Extra cells $text may occupy beyond the vendor's own count. */
    public static function extra(string $text): int
    {
        $extra = 0;
        foreach (self::WIDE_GLYPHS as $glyph) {
            $extra += substr_count($text, $glyph);
        }

        return $extra;
    }

    /** Cells a conservative emoji-aware terminal charges for $text. */
    public static function string(string $text): int
    {
        return Width::string($text) + self::extra($text);
    }

    /**
     * Cut $text to at most $cells conservative cells. The vendor cut runs
     * first; while the emoji reservation is exceeded the budget shrinks by
     * the surviving glyph count, so every iteration strictly shortens the
     * vendor width and terminates.
     */
    public static function truncate(string $text, int $cells): string
    {
        if ($cells <= 0) {
            return '';
        }

        $out = Width::truncate($text, $cells);
        while (self::string($out) > $cells) {
            $out = Width::truncate($out, $cells - self::extra($out));
        }

        return $out;
    }
}

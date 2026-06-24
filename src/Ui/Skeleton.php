<?php

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Width;

/**
 * A pure, stateless "shimmer" loading placeholder: rows of a base glyph (`░`)
 * with a brighter band that SWEEPS horizontally as an animation phase advances,
 * mimicking the shimmer skeletons used while a screen fetches its first data.
 *
 * The render is a deterministic function of (width, lines, phase, theme) only —
 * no internal state, no time source — so the App drives the animation by feeding
 * an ever-advancing phase (see {@see \Phlix\Console\App}'s shimmer tick). Each row
 * is built to EXACTLY $width visible cells, measured with {@see Width} so any SGR
 * the theme injects never shifts the layout (ANSI-safe).
 *
 * Phase → band position
 * ---------------------
 * The bright band is {@see BAND} cells wide. It travels across a virtual track of
 * `$width + BAND` columns so it can slide fully off the right edge before it
 * reappears — a continuous left-to-right sweep that wraps cleanly. The band's
 * left-edge column for a given phase is:
 *
 *     $bandLeft = ($phase % ($width + BAND)) - (BAND - 1)
 *
 * so at phase 0 only the band's rightmost cell sits at column 0 (it enters from
 * the left edge), and as the phase grows the band marches right and exits past
 * `$width`, then the modulo wraps it back. A cell at column `c` is "bright" iff
 * `$bandLeft <= c < $bandLeft + BAND` AND `0 <= c < $width`. The same (width,
 * lines, phase) always yields byte-identical output.
 */
final class Skeleton
{
    /** The dim base glyph that fills a skeleton row. */
    private const BASE = '░';

    /** The bright glyph forming the moving shimmer band (un-themed look). */
    private const BRIGHT = '▓';

    /** The mid glyph trailing the band's leading cell (un-themed look). */
    private const MID = '▒';

    /** How many cells wide the moving shimmer band is. */
    private const BAND = 3;

    /**
     * Render $lines rows, each exactly $width visible cells, of a shimmer
     * skeleton whose bright band is positioned by $phase. A non-positive $width
     * or $lines yields an empty string (nothing to draw).
     *
     * Under a coloured $theme the band is the brand accent applied over the
     * base glyph (so the whole row reads as one tinted surface); under Nocturne
     * (or no theme) the band is the brighter block glyphs over the dim base —
     * either way the row measures exactly $width cells.
     */
    public static function bars(int $width, int $lines, int $phase, ?Theme $theme = null): string
    {
        if ($width <= 0 || $lines <= 0) {
            return '';
        }

        $row = self::row($width, $phase, $theme);

        return implode("\n", array_fill(0, $lines, $row));
    }

    /**
     * A SINGLE shimmer row exactly $width cells wide (the list screens use this
     * for a one-line placeholder). Shares the phase→position math with
     * {@see bars()}.
     */
    public static function line(int $width, int $phase, ?Theme $theme = null): string
    {
        return $width <= 0 ? '' : self::row($width, $phase, $theme);
    }

    /**
     * Build one shimmer row of exactly $width visible cells. The band's leading
     * cell is BRIGHT, its trailing cells MID, everything else the dim BASE. Under
     * a coloured theme the bright/mid cells are wrapped in the brand accent so the
     * sweep reads as a lighter tint; Nocturne keeps the plain block glyphs (the
     * style is a no-op there, so the row is byte-identical to the un-themed look).
     */
    private static function row(int $width, int $phase, ?Theme $theme): string
    {
        // The band marches across width + BAND columns so it slides fully off the
        // right edge before wrapping (a clean, continuous sweep).
        $bandLeft = ($phase % ($width + self::BAND)) - (self::BAND - 1);

        $accent = ($theme ?? Theme::nocturne())->brandStyle();

        $out = '';
        for ($c = 0; $c < $width; $c++) {
            $inBand = $c >= $bandLeft && $c < $bandLeft + self::BAND;
            if (!$inBand) {
                $out .= self::BASE;
                continue;
            }

            // The leading (rightmost) band cell is the brightest; the rest MID.
            $glyph = ($c === $bandLeft + self::BAND - 1) ? self::BRIGHT : self::MID;
            // brandStyle() is a no-op under Nocturne (zero SGR), so this is the
            // plain glyph there; a coloured theme tints just the band cell.
            $out .= $accent->render($glyph);
        }

        // Defensive: the loop emits exactly $width visible cells already, but a
        // pad-right keeps the contract explicit and absorbs any future glyph that
        // measured differently than 1 cell.
        return Width::padRight($out, $width);
    }
}

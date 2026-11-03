<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Media;

use SugarCraft\Image\Mosaic;

/**
 * Builds a {@see Mosaic} for a render mode chosen on the command line
 * (`--mode=…`); when none is forced, both entry points select the universal
 * half-block cell renderer (the safe default — see {@see forMode()}).
 *
 * Two audiences:
 *
 *  - {@see forMode()} — a *single* full-cell image (e.g. the `phlix poster`
 *    spike). Every backend is fair game here, including the pixel-graphics
 *    protocols (sixel / kitty / iTerm2), because one image is emitted on its own.
 *
 *  - {@see forPosterGrid()} — the *tiled* poster rails/grids in the full app.
 *    Those are composed as text cells (cards stitched side by side, line by
 *    line), which only works for cell-based renderers — the block and ASCII/ANSI
 *    renderers emit one terminal line per cell row, so they align. A graphics
 *    protocol emits one opaque DCS/escape blob with no per-row structure;
 *    stitching it beside other cards injects cell-padding spaces into the middle
 *    of the sequence and shreds it. So the grid forces a cell-based renderer and
 *    reports a notice when a graphics mode was asked for.
 *
 * Centralising this also keeps the cache correct: {@see PosterLoader} keys its
 * disk cache by {@see Mosaic::protocol()}, so each mode caches separately.
 */
final class MosaicFactory
{
    /**
     * Resolve a mode string to a Mosaic for a single full-cell image. `null` /
     * `auto` select the app's safe default — the universal half-block cell
     * renderer — exactly like {@see forPosterGrid()}; any other value forces
     * that backend (throwing on an unknown name).
     *
     * [review console__qa CONSOLIDATED M17] `Mosaic::auto()` used to probe the
     * terminal here, but the vendored probe misdetects on ordinary hosts
     * (tmux/generic terms): the spike buried users under ~90k malformed lines
     * per invocation while every explicit mode rendered the exact Fit grid.
     * Auto-probing buys a first-run flourish; it does not buy correctness, and
     * the flourish is one `--mode=sixel` away. The universal cell renderer is
     * the honest default; graphics protocols stay reachable explicitly.
     *
     * Cell modes (all tile): `halfblock` (≡ `ansi`, 24-bit colour blocks),
     * `quarterblock` (denser blocks), `ascii` (monochrome character ramp),
     * `ansi256` (256-colour chars), `truecolor` (24-bit-colour chars).
     * Graphics modes (single image only): `sixel`, `kitty`, `iterm2`.
     *
     * The mode vocabulary itself belongs to sugar-image ({@see Mosaic::fromModeString()});
     * this factory only maps null/auto onto the safe-default policy and turns
     * an unrecognised name into a hard error.
     */
    public static function forMode(?string $mode): Mosaic
    {
        if ($mode === null || $mode === 'auto') {
            return Mosaic::halfBlock();
        }

        return Mosaic::fromModeString($mode)
            ?? throw new \InvalidArgumentException("Unknown render mode: {$mode}");
    }

    /**
     * Resolve a mode to a Mosaic for the poster grid.
     *
     * Cell modes (half/quarter-block, ascii/ansi256/truecolor) tile as text;
     * graphics modes (sixel/kitty/iTerm2) tile via sugar-core's image overlay
     * (the {@see PosterLoader} routes them by {@see Mosaic::isInline()}). `null` /
     * `auto` default to half-block — a safe, universal inline renderer; pass an
     * explicit `--mode=sixel` to opt into graphics.
     */
    public static function forPosterGrid(?string $mode): Mosaic
    {
        return ($mode === null || $mode === 'auto') ? Mosaic::halfBlock() : self::forMode($mode);
    }
}

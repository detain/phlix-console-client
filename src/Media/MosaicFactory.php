<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Media;

use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Renderer\AsciiColorMode;
use SugarCraft\Mosaic\Renderer\KittyRenderer;

/**
 * Builds a {@see Mosaic} for a render mode chosen on the command line
 * (`--mode=…`), or auto-detects the best protocol when none is forced.
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
     * `auto` auto-detect the terminal; any other value forces that backend
     * (throwing on an unknown name).
     *
     * Cell modes (all tile): `halfblock` (≡ `ansi`, 24-bit colour blocks),
     * `quarterblock` (denser blocks), `ascii` (monochrome character ramp),
     * `ansi256` (256-colour chars), `truecolor` (24-bit-colour chars).
     * Graphics modes (single image only): `sixel`, `kitty`, `iterm2`.
     */
    public static function forMode(?string $mode): Mosaic
    {
        return match ($mode) {
            null, 'auto'              => Mosaic::auto(),
            'sixel'                   => Mosaic::sixel(),
            'iterm2'                  => Mosaic::iterm2(),
            'halfblock', 'half', 'ansi' => Mosaic::halfBlock(),
            'quarterblock', 'quarter' => Mosaic::quarterBlock(),
            'ascii'                   => Mosaic::ascii(AsciiColorMode::Mono),
            'ansi256'                 => Mosaic::ascii(AsciiColorMode::Ansi256),
            'truecolor'               => Mosaic::ascii(AsciiColorMode::TrueColor),
            // candy-mosaic has no Mosaic::kitty() factory; build it explicitly.
            'kitty'                   => Mosaic::builder()->withRenderer(new KittyRenderer())->build(),
            default                   => throw new \InvalidArgumentException("Unknown render mode: {$mode}"),
        };
    }

    /**
     * Resolve a mode to a Mosaic for the poster grid.
     *
     * Cell modes (half/quarter-block, ascii/ansi256/truecolor) tile as text;
     * graphics modes (sixel/kitty/iTerm2) tile via candy-core's image overlay
     * (the {@see PosterLoader} routes them by {@see Mosaic::isInline()}). `null` /
     * `auto` default to half-block — a safe, universal inline renderer; pass an
     * explicit `--mode=sixel` to opt into graphics.
     */
    public static function forPosterGrid(?string $mode): Mosaic
    {
        return ($mode === null || $mode === 'auto') ? Mosaic::halfBlock() : self::forMode($mode);
    }
}

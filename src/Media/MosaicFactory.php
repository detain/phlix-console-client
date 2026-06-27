<?php

declare(strict_types=1);

namespace Phlix\Console\Media;

use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Renderer\KittyRenderer;

/**
 * Builds a {@see Mosaic} for a render mode chosen on the command line
 * (`--mode=…`), or auto-detects the best protocol when none is forced.
 *
 * Centralising this keeps the mode→renderer mapping in one place so the
 * `poster` spike and the full `run` app pick the *same* backend — which matters
 * because {@see PosterLoader} keys its disk cache by `Mosaic::protocol()`. A
 * forced `--mode=sixel` therefore renders sixel AND caches under a sixel key, so
 * it never serves a half-block (ANSI) image that was cached in a different mode.
 */
final class MosaicFactory
{
    /**
     * Resolve a mode string to a Mosaic. `null`/`auto` auto-detect the terminal;
     * any other value forces that backend (and throws on an unknown name).
     */
    public static function forMode(?string $mode): Mosaic
    {
        return match ($mode) {
            null, 'auto'        => Mosaic::auto(),
            'sixel'             => Mosaic::sixel(),
            'iterm2'            => Mosaic::iterm2(),
            'halfblock', 'half' => Mosaic::halfBlock(),
            // candy-mosaic has no Mosaic::kitty() factory; build it explicitly.
            'kitty'             => Mosaic::builder()->withRenderer(new KittyRenderer())->build(),
            default             => throw new \InvalidArgumentException("Unknown render mode: {$mode}"),
        };
    }
}

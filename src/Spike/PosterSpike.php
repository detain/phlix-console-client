<?php

declare(strict_types=1);

namespace Phlix\Console\Spike;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Renderer\KittyRenderer;
use SugarCraft\Mosaic\Scale;

/**
 * Phase-0 proof: load an image from disk and render it to the terminal at a
 * given cell box using the best available protocol (or a forced mode). This is
 * the same call path the real poster grid will use, minus the async fetch +
 * cache that land in Phase 1.
 */
final class PosterSpike
{
    public function render(string $path, int $width = 40, ?int $height = null, ?string $mode = null): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Image not found: {$path}");
        }

        return $this->mosaicFor($mode)
            ->withScale(Scale::Fit)
            ->render(ImageSource::fromFile($path), $width, $height);
    }

    private function mosaicFor(?string $mode): Mosaic
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

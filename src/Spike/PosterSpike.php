<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Spike;

use Phlix\Console\Media\MosaicFactory;
use SugarCraft\Mosaic\ImageSource;
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

        return MosaicFactory::forMode($mode)
            ->withScale(Scale::Fit)
            ->render(ImageSource::fromFile($path), $width, $height);
    }
}

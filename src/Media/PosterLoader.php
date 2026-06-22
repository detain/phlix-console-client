<?php

declare(strict_types=1);

namespace Phlix\Console\Media;

use React\Promise\PromiseInterface;
use SugarCraft\Mosaic\DiskCache;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Scale;

use function React\Promise\resolve;

/**
 * Fetches a poster URL and renders it to ANSI at a target cell size, using a
 * persistent {@see DiskCache} so a redraw (or a later session) is an instant
 * file read. The async fetch keeps the render loop responsive.
 */
final class PosterLoader
{
    public function __construct(
        private readonly Mosaic $mosaic,
        private readonly ?DiskCache $cache = null,
    ) {
    }

    /**
     * Resolve with the rendered ANSI for $url at $width × $height cells.
     * A cache hit resolves immediately; a miss fetches, renders (Fill scale),
     * caches, and resolves.
     *
     * @return PromiseInterface<string>
     */
    public function load(string $url, int $width, int $height): PromiseInterface
    {
        $key = DiskCache::key($url, $width, $height, $this->mosaic->protocol());

        if ($this->cache !== null) {
            $hit = $this->cache->get($key);
            if ($hit !== null) {
                return resolve($hit);
            }
        }

        return ImageSource::fromUrlAsync($url)->then(function (ImageSource $image) use ($key, $width, $height): string {
            $ansi = $this->mosaic->withScale(Scale::Fill)->render($image, $width, $height);
            $this->cache?->put($key, $ansi);

            return $ansi;
        });
    }
}

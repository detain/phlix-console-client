<?php

declare(strict_types=1);

namespace Phlix\Console\Media;

use React\Promise\PromiseInterface;
use SugarCraft\Core\ImageLayer;
use SugarCraft\Mosaic\DiskCache;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Scale;

use function React\Promise\resolve;

/**
 * Fetches a poster URL and renders it to ANSI at a target cell size, using a
 * persistent {@see DiskCache} so a redraw (or a later session) is an instant
 * file read. The async fetch keeps the render loop responsive.
 *
 * Inline renderers (half/quarter-block, ASCII) produce cell text that resolves
 * straight into the poster. Pixel-graphics renderers (sixel/kitty/iTerm2) — for
 * which {@see Mosaic::isInline()} is false — produce an opaque blob that can't be
 * stitched into a text rail, so the blob is handed to candy-core's
 * {@see ImageLayer}: {@see load()} resolves with a marker block and the runtime
 * paints the real bytes on top. The owning model exposes {@see imageLayer()} on
 * its {@see \SugarCraft\Core\View} so the runtime can resolve the markers.
 */
final class PosterLoader
{
    private readonly bool $inline;
    private readonly ImageLayer $images;

    public function __construct(
        private readonly Mosaic $mosaic,
        private readonly ?DiskCache $cache = null,
    ) {
        $this->inline = $mosaic->isInline();
        $this->images = new ImageLayer();
    }

    /**
     * Resolve with the rendered ANSI for $url at $width × $height cells.
     *
     * Inline mode resolves with the poster's cell ANSI (a cache hit resolves
     * immediately). Overlay mode resolves with a marker block of the same size
     * and stashes the rendered bytes in {@see imageLayer()} for the runtime.
     *
     * @return PromiseInterface<string>
     */
    public function load(string $url, int $width, int $height): PromiseInterface
    {
        $key = DiskCache::key($url, $width, $height, $this->mosaic->protocol());

        $hit = $this->cache?->get($key);
        if ($hit !== null) {
            return resolve($this->present($hit, $width, $height));
        }

        return ImageSource::fromUrlAsync($url)->then(function (ImageSource $image) use ($key, $width, $height): string {
            $bytes = $this->mosaic->withScale(Scale::Fill)->render($image, $width, $height);
            $this->cache?->put($key, $bytes);

            return $this->present($bytes, $width, $height);
        });
    }

    /**
     * The overlay image layer (id → {@see \SugarCraft\Core\ImagePlacement})
     * accumulated so far, empty in inline mode. Hand this to the
     * {@see \SugarCraft\Core\View} so the runtime paints each marker the frame
     * contains — and clears it precisely when it scrolls away.
     *
     * @return array<int, \SugarCraft\Core\ImagePlacement>
     */
    public function imageLayer(): array
    {
        return $this->images->placements();
    }

    /**
     * The render protocol in use ('halfblock' | 'quarterblock' | 'ascii' |
     * 'ansi256' | 'truecolor' | 'sixel' | 'kitty' | 'iterm2'). Lets the app open
     * the video player in the same mode the poster grid is using.
     */
    public function protocol(): string
    {
        return $this->mosaic->protocol();
    }

    /**
     * Inline mode → the bytes are the poster. Overlay mode → register the bytes
     * with the {@see ImageLayer} and return a marker block for the text frame.
     */
    private function present(string $bytes, int $width, int $height): string
    {
        return $this->inline ? $bytes : $this->images->place($bytes, $width, $height);
    }
}

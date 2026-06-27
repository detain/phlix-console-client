<?php

declare(strict_types=1);

namespace Phlix\Console\Media;

use React\Promise\PromiseInterface;
use SugarCraft\Core\ImageOverlay;
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
 * **Overlay mode.** Pixel-graphics protocols (sixel/kitty/iTerm2) produce one
 * opaque escape blob that can't be stitched into a text-cell rail. When
 * `$overlay` is set, {@see load()} instead registers the blob in an image layer
 * keyed by a stable id and resolves with a one-cell-marker *block* (see
 * {@see ImageOverlay}) sized to the request. The caller stores that block like
 * any poster; the runtime later paints the real bytes on top of the text frame.
 * The owning model exposes {@see imageLayer()} on its {@see \SugarCraft\Core\View}
 * so the runtime can resolve the markers.
 */
final class PosterLoader
{
    /** @var array<string, int> cache key → overlay image id (stable per poster+size). */
    private array $idByKey = [];

    /** @var array<int, string> overlay image id → raw protocol bytes. */
    private array $blobById = [];

    public function __construct(
        private readonly Mosaic $mosaic,
        private readonly ?DiskCache $cache = null,
        private readonly bool $overlay = false,
    ) {
    }

    /**
     * Resolve with the rendered ANSI for $url at $width × $height cells.
     *
     * In inline mode this is the poster's cell ANSI (cache hit resolves
     * immediately). In {@see $overlay} mode it is a marker block of the same
     * size, and the rendered bytes are stashed in {@see imageLayer()} for the
     * runtime to paint.
     *
     * @return PromiseInterface<string>
     */
    public function load(string $url, int $width, int $height): PromiseInterface
    {
        $key = DiskCache::key($url, $width, $height, $this->mosaic->protocol());

        $hit = $this->cache?->get($key);
        if ($hit !== null) {
            return resolve($this->present($key, $hit, $width, $height));
        }

        return ImageSource::fromUrlAsync($url)->then(function (ImageSource $image) use ($key, $width, $height): string {
            $bytes = $this->mosaic->withScale(Scale::Fill)->render($image, $width, $height);
            $this->cache?->put($key, $bytes);

            return $this->present($key, $bytes, $width, $height);
        });
    }

    /**
     * The overlay image layer (id → raw protocol bytes) accumulated so far.
     * Empty in inline mode. Hand this to the {@see \SugarCraft\Core\View} so the
     * runtime paints each marker the frame contains.
     *
     * @return array<int, string>
     */
    public function imageLayer(): array
    {
        return $this->blobById;
    }

    /**
     * Inline mode → the bytes are the poster. Overlay mode → register the bytes
     * under a stable id and return a marker block of the requested size for the
     * text frame (or a blank block if the id space is exhausted).
     */
    private function present(string $key, string $bytes, int $width, int $height): string
    {
        if (!$this->overlay) {
            return $bytes;
        }

        $id = $this->idByKey[$key] ??= count($this->idByKey);
        if ($id >= ImageOverlay::MAX_IMAGES) {
            return self::blankBlock($width, $height);
        }

        $this->blobById[$id] = $bytes;

        return self::markerBlock($id, $width, $height);
    }

    /** A $width × $height block: a one-cell marker top-left, blank elsewhere. */
    private static function markerBlock(int $id, int $width, int $height): string
    {
        $width = max(1, $width);
        $rows = [ImageOverlay::marker($id) . str_repeat(' ', $width - 1)];
        for ($i = 1; $i < max(1, $height); $i++) {
            $rows[] = str_repeat(' ', $width);
        }

        return implode("\n", $rows);
    }

    private static function blankBlock(int $width, int $height): string
    {
        return implode("\n", array_fill(0, max(1, $height), str_repeat(' ', max(1, $width))));
    }
}

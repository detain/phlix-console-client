<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Media;

use React\Promise\PromiseInterface;
use SugarCraft\Mosaic\DiskCache;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Scale;

use function React\Promise\resolve;

/**
 * The result of {@see PosterLoader::load()}: in inline mode the marker is the
 * rendered poster bytes and imageId is null; in overlay mode the marker is the
 * placeholder cell block and imageId is the assigned overlay image ID.
 */
final readonly class PosterLoadResult
{
    public function __construct(
        public string $marker,
        public ?int $imageId,
    ) {
    }
}

/**
 * Fetches a poster URL and renders it to ANSI at a target cell size, using a
 * persistent {@see DiskCache} so a redraw (or a later session) is an instant
 * file read. The async fetch keeps the render loop responsive.
 *
 * Inline renderers (half/quarter-block, ASCII) produce cell text that resolves
 * straight into the poster. Pixel-graphics renderers (sixel/kitty/iTerm2) — for
 * which {@see Mosaic::isInline()} is false — produce an opaque blob that can't be
 * stitched into a text rail, so the blob is handed to candy-mosaic's
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
     * @return PromiseInterface<PosterLoadResult>
     */
    public function load(string $url, int $width, int $height): PromiseInterface
    {
        $key = DiskCache::key($url, $width, $height, $this->mosaic->protocol());

        $hit = $this->cache?->get($key);
        if ($hit !== null) {
            return resolve($this->present($hit, $width, $height));
        }

        if (!$this->isValidImageUrl($url)) {
            throw new \InvalidArgumentException('Invalid or missing URL scheme');
        }

        // Phlix only ever loads image URLs handed back by its own configured
        // server, which for a self-hosted deployment is routinely on localhost
        // or a LAN address. candy-mosaic's fromUrlAsync() SSRF guard rejects
        // private/reserved hosts by default, so allow-list the URL's own host —
        // any cross-host redirect stays guarded.
        $host = parse_url($url, PHP_URL_HOST);
        $allowedHosts = is_string($host) && $host !== '' ? [$host] : null;

        return ImageSource::fromUrlAsync($url, allowedHosts: $allowedHosts)->then(function (ImageSource $image) use ($key, $width, $height): PosterLoadResult {
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
     * The terminal's detected cell pixel size, or null when the terminal did not
     * report one. Lets the video player decode graphics-mode frames at the real
     * full pixel resolution (cells × cell-pixel-size) instead of an assumed box.
     *
     * @return array{cellWidth:int,cellHeight:int}|null
     */
    public function cellSize(): ?array
    {
        return $this->mosaic->fontSize();
    }

    /**
     * Inline mode → the bytes are the poster. Overlay mode → register the bytes
     * with the {@see ImageLayer} and return a marker block for the text frame.
     */
    private function present(string $bytes, int $width, int $height): PosterLoadResult
    {
        if ($this->inline) {
            return new PosterLoadResult($bytes, null);
        }

        $result = $this->images->place($bytes, $width, $height);

        return new PosterLoadResult($result['marker'], $result['imageId']);
    }

    /**
     * True when the renderer produces inline cell text (halfblock / quarterblock /
     * ascii / ansi256 / truecolor). False when it produces pixel-graphics blobs
     * that must be painted as overlays (sixel / kitty / iterm2).
     */
    public function isInline(): bool
    {
        return $this->inline;
    }

    /**
     * Validates that a URL is a proper HTTP/HTTPS image URL.
     */
    private function isValidImageUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null || !in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === '') {
            return false;
        }

        return true;
    }
}

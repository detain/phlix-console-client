<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Media;

use React\Promise\PromiseInterface;
use SugarCraft\Core\Util\Semaphore;
use SugarCraft\Mosaic\DiskCache;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\Mosaic;

/**
 * The result of {@see PosterLoader::load()}: in inline mode the marker is the
 * rendered poster bytes and imageId is null; in overlay mode the marker is the
 * placeholder cell block and imageId is the assigned overlay image ID.
 * The digest is the {@see ImageLayer::digestFor()} window key of bytes+size and
 * is non-null in overlay mode.
 */
final readonly class PosterLoadResult
{
    public function __construct(
        public string $marker,
        public ?int $imageId,
        public ?string $digest = null,
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
    /** Poster fetches in flight when PHLIX_POSTER_CONCURRENCY is unset. */
    private const DEFAULT_CONCURRENCY = 6;

    private readonly bool $inline;
    private readonly ImageLayer $images;

    private readonly Semaphore $semaphore;

    public function __construct(
        private readonly Mosaic $mosaic,
        private readonly ?DiskCache $cache = null,
        ?Semaphore $semaphore = null,
    ) {
        $this->inline = $mosaic->isInline();
        $this->images = new ImageLayer();
        $this->semaphore = $semaphore ?? Semaphore::new(self::concurrencyLimit());
    }

    /**
     * The semaphore used to bound concurrent poster operations.
     */
    public function semaphore(): Semaphore
    {
        return $this->semaphore;
    }

    /**
     * Resolve with the rendered ANSI for $url at $width × $height cells.
     *
     * Inline mode resolves with the poster's cell ANSI (a cache hit resolves
     * immediately). Overlay mode resolves with a marker block of the same size
     * and stashes the rendered bytes in {@see imageLayer()} for the runtime.
     *
     * Concurrency is capped by the semaphore (controlled by PHLIX_POSTER_CONCURRENCY,
     * defaults to 6).
     *
     * @return PromiseInterface<PosterLoadResult>
     */
    public function load(string $url, int $width, int $height): PromiseInterface
    {
        if (!$this->isValidImageUrl($url)) {
            throw new \InvalidArgumentException('Invalid or missing URL scheme');
        }

        // Fetch, render and disk-cache in one upstream call: Mosaic::posterAsync()
        // owns the cache key, the Fill-by-default poster scaling and populating
        // the cache on a miss, so the client no longer restates any of that.
        //
        // Note the deliberate trade-off: posterAsync() consults the cache itself,
        // so a warm hit now waits for a semaphore permit instead of resolving
        // instantly the way a client-side pre-check did. Bounded and self-healing,
        // and it keeps the key formula upstream — the alternative is restating
        // DiskCache::key() here, which is exactly the duplication this removes.
        return $this->semaphore->run(
            fn (): PromiseInterface => $this->mosaic
                ->posterAsync($url, $width, $height, $this->cache, $this->allowedHosts($url))
                ->then(fn (string $bytes): PosterLoadResult => $this->present($bytes, $width, $height)),
        );
    }

    /**
     * Phlix only ever loads image URLs handed back by its own configured server,
     * which for a self-hosted deployment is routinely on localhost or a LAN
     * address. candy-mosaic's SSRF guard rejects private/reserved hosts by
     * default, so allow-list the URL's own host — any cross-host redirect stays
     * guarded. The host VALUES are phlix knowledge and stay here; the guarding
     * mechanism itself is upstream's.
     *
     * @return list<string>|null
     */
    private function allowedHosts(string $url): ?array
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? [$host] : null;
    }

    /**
     * Read the app's poster fan-out cap out of the environment. A foundation
     * semaphore takes its limit as an argument and reads nothing from the
     * process; this env var is phlix configuration, so the lookup lives here.
     */
    private static function concurrencyLimit(): int
    {
        $env = $_ENV['PHLIX_POSTER_CONCURRENCY'] ?? $_SERVER['PHLIX_POSTER_CONCURRENCY'] ?? null;

        if ($env === null) {
            return self::DEFAULT_CONCURRENCY;
        }

        $parsed = filter_var($env, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed !== false ? $parsed : self::DEFAULT_CONCURRENCY;
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
     *
     * Dedup is the layer's own: identical bytes at an identical footprint reuse
     * the image id they were already assigned, so a poster that scrolls back into
     * view costs no second terminal allocation. The window digest returned is the
     * key {@see release()}/{@see releaseAllExcept()} take.
     */
    private function present(string $bytes, int $width, int $height): PosterLoadResult
    {
        if ($this->inline) {
            return new PosterLoadResult($bytes, null);
        }

        $digest = ImageLayer::digestFor($bytes, $width, $height);
        $placed = $this->images->placeTracked($bytes, $width, $height);

        return new PosterLoadResult($placed->marker, $placed->imageId, $digest);
    }

    /**
     * Release a placement by its {@see ImageLayer::digestFor()} window key,
     * unregistering it from the layer. Safe to call even if the digest is not
     * currently tracked.
     *
     * The delete sequence `release()` returns is deliberately discarded: this
     * layer is built without a renderer (see the constructor), so upstream
     * always yields an empty string here — same as the `removeById()` call this
     * replaced. Should the layer ever carry a renderer, the sequences this
     * returns must be plumbed through to the terminal output.
     */
    public function release(string $digest): void
    {
        $imageId = $this->images->imageIdForDigest($digest);
        // A digest with no live id was never placed, or was already released.
        if ($imageId !== null) {
            $this->images->release($imageId);
        }
    }

    /**
     * Release all placements except those whose digests are in $keepDigests.
     * Used when the visible window scrolls: keep the visible items plus a margin,
     * release everything else to free memory.
     *
     * @param list<string> $keepDigests
     */
    public function releaseAllExcept(array $keepDigests): void
    {
        $keepIds = [];
        foreach ($keepDigests as $digest) {
            $imageId = $this->images->imageIdForDigest($digest);
            if ($imageId !== null) {
                $keepIds[] = $imageId;
            }
        }

        $this->images->releaseAllExcept($keepIds);
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

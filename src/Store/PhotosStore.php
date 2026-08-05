<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Photo;
use Phlix\Console\Api\Dto\PhotoAlbumPage;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Caches photo album pages over the {@see ApiClient} with a short TTL,
 * de-duplicating concurrent fetches. Pages are keyed by libraryId+offset so
 * scrolling to a previously-visited region never re-fetches. The screen uses
 * {@see ensureRange()} to fetch only the visible window (plus overscan), so
 * even a large library scrolls smoothly.
 *
 * Also caches single photo details via {@see photo()}.
 */
final class PhotosStore
{
    /** Maximum number of page entries to cache. */
    private const PAGE_CAPACITY = 2000;

    /** Maximum number of item/detail entries to cache. */
    private const ITEM_CAPACITY = 500;

    /** @var LruMap */
    private LruMap $pages;

    /** @var array<string, PromiseInterface<PhotoAlbumPage>>  page key → in-flight fetch */
    private array $inFlight = [];

    /** @var LruMap */
    private LruMap $photos;

    /** @var array<string, PromiseInterface<Photo>>  photo id → in-flight detail fetch */
    private array $photosInFlight = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private const DEFAULT_LIMIT = 100;

    /**
     * @param (\Closure(): float)|null $clock
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly float $ttl = 60.0,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->pages = new LruMap(self::PAGE_CAPACITY);
        $this->photos = new LruMap(self::ITEM_CAPACITY);
    }

    /**
     * Fetch the page(s) covering the absolute-index window [$start, $end] for a
     * library and resolve the albums keyed by their ABSOLUTE index, plus the
     * total. Pages are cached and de-duplicated so a scroll that revisits an
     * in-flight offset never doubles the request.
     *
     * @return PromiseInterface<PhotoRange>
     */
    public function ensureRange(string $libraryId, int $start, int $end, int $limit = self::DEFAULT_LIMIT): PromiseInterface
    {
        if ($end < 0 || $start > $end) {
            return resolve(new PhotoRange([], 0));
        }

        $start = max(0, $start);
        $windowEnd = max($start, $end - 1);
        $firstOffset = intdiv($start, $limit) * $limit;
        $lastOffset = intdiv($windowEnd, $limit) * $limit;

        /** @var array<int, PromiseInterface<PhotoAlbumPage>> $promises */
        $promises = [];
        for ($offset = $firstOffset; $offset <= $lastOffset; $offset += $limit) {
            $promises[$offset] = $this->page($libraryId, $offset, $limit);
        }

        return all($promises)->then(static function (array $pages) use ($start, $end): PhotoRange {
            $albums = [];
            $total = 0;
            foreach ($pages as $offset => $page) {
                $total = max($total, $page->total);
                foreach ($page->albums as $i => $album) {
                    $absolute = $offset + $i;
                    if ($absolute >= $start && $absolute <= $end) {
                        $albums[$absolute] = $album;
                    }
                }
            }
            ksort($albums);

            return new PhotoRange($albums, $total);
        });
    }

    /**
     * @return PromiseInterface<PhotoAlbumPage>
     */
    private function page(string $libraryId, int $offset, int $limit): PromiseInterface
    {
        $key = "{$libraryId}:{$offset}:{$limit}";
        $now = ($this->clock)();

        $entry = $this->pages->peek($key);
        /** @var array{page: PhotoAlbumPage, at: float}|null $entry */
        if ($entry !== null && ($now - $entry['at']) < $this->ttl) {
            $cached = $this->pages->get($key);
            /** @var array{page: PhotoAlbumPage, at: float} $cached */
            return resolve($cached['page']);
        }

        if (isset($this->inFlight[$key])) {
            return $this->inFlight[$key];
        }

        /** @var Deferred<PhotoAlbumPage> $deferred */
        $deferred = new Deferred();
        $this->inFlight[$key] = $deferred->promise();

        $this->api->photoAlbums($libraryId, $limit, $offset)->then(
            function (PhotoAlbumPage $page) use ($key, $now, $deferred): void {
                $this->pages->set($key, ['page' => $page, 'at' => $now]);
                unset($this->inFlight[$key]);
                $deferred->resolve($page);
            },
            function (\Throwable $error) use ($key, $deferred): void {
                unset($this->inFlight[$key]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * A single photo's detail — the shape that adds the full EXIF map — TTL-cached
     * and de-duplicated.
     *
     * @return PromiseInterface<Photo>
     */
    public function photo(string $id, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        $entry = $this->photos->peek($id);
        /** @var array{photo: Photo, at: float}|null $entry */
        if (!$force && $entry !== null && ($now - $entry['at']) < $this->ttl) {
            $cached = $this->photos->get($id);
            /** @var array{photo: Photo, at: float} $cached */
            return resolve($cached['photo']);
        }

        if (isset($this->photosInFlight[$id])) {
            return $this->photosInFlight[$id];
        }

        /** @var Deferred<Photo> $deferred */
        $deferred = new Deferred();
        $this->photosInFlight[$id] = $deferred->promise();

        $this->api->photo($id)->then(
            function (Photo $photo) use ($id, $now, $deferred): void {
                $this->photos->set($id, ['photo' => $photo, 'at' => $now]);
                unset($this->photosInFlight[$id]);
                $deferred->resolve($photo);
            },
            function (\Throwable $error) use ($id, $deferred): void {
                unset($this->photosInFlight[$id]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    public function invalidate(): void
    {
        $this->pages->clear();
        $this->inFlight = [];
        $this->photos->clear();
        $this->photosInFlight = [];
    }
}
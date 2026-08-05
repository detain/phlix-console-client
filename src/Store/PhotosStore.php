<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Photo;
use Phlix\Console\Api\Dto\PhotoAlbum;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Caches the date-grouped photo albums per library (the server returns every
 * album, each with its full photo list, in one `/photo/albums` call) and single
 * photo details over the {@see ApiClient} with a short TTL, de-duplicating
 * concurrent fetches. Mirrors {@see MusicStore} (the per-library album list) and
 * {@see BooksStore} (the single-detail cache).
 */
final class PhotosStore
{
    /** Maximum number of page entries to cache. */
    private const PAGE_CAPACITY = 2000;

    /** Maximum number of item/detail entries to cache. */
    private const ITEM_CAPACITY = 500;

    /** @var LruMap */
    private LruMap $albums;

    /** @var array<string, PromiseInterface<list<PhotoAlbum>>>  library id → in-flight album fetch */
    private array $albumsInFlight = [];

    /** @var LruMap */
    private LruMap $photos;

    /** @var array<string, PromiseInterface<Photo>>  photo id → in-flight detail fetch */
    private array $photosInFlight = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly float $ttl = 60.0,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->albums = new LruMap(self::PAGE_CAPACITY);
        $this->photos = new LruMap(self::ITEM_CAPACITY);
    }

    /**
     * The date-grouped album list for a library, TTL-cached per library id.
     * Concurrent calls for the same library share one fetch.
     *
     * @return PromiseInterface<list<PhotoAlbum>>
     */
    public function albums(string $libraryId, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        $entry = $this->albums->peek($libraryId);
        /** @var array{albums: list<PhotoAlbum>, at: float}|null $entry */
        if (!$force && $entry !== null && ($now - $entry['at']) < $this->ttl) {
            $cached = $this->albums->get($libraryId);
            /** @var array{albums: list<PhotoAlbum>, at: float} $cached */
            return resolve($cached['albums']);
        }

        if (isset($this->albumsInFlight[$libraryId])) {
            return $this->albumsInFlight[$libraryId];
        }

        // Drive a Deferred so the in-flight guard is registered before the inner
        // request can settle (react may resolve synchronously) and cleared
        // exactly once on settle/reject.
        /** @var Deferred<list<PhotoAlbum>> $deferred */
        $deferred = new Deferred();
        $this->albumsInFlight[$libraryId] = $deferred->promise();

        $this->api->photoAlbums($libraryId)->then(
            function (array $albums) use ($libraryId, $now, $deferred): void {
                $this->albums->set($libraryId, ['albums' => $albums, 'at' => $now]);
                unset($this->albumsInFlight[$libraryId]);
                $deferred->resolve($albums);
            },
            function (\Throwable $error) use ($libraryId, $deferred): void {
                unset($this->albumsInFlight[$libraryId]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * A single photo's detail — the shape that adds the full EXIF map — TTL-cached
     * and de-duplicated like {@see albums()}.
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
        $this->albums->clear();
        $this->albumsInFlight = [];
        $this->photos->clear();
        $this->photosInFlight = [];
    }
}

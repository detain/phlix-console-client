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
    /** @var array<string, array{albums: list<PhotoAlbum>, at: float}>  library id → cached albums */
    private array $albums = [];

    /** @var array<string, PromiseInterface<list<PhotoAlbum>>>  library id → in-flight album fetch */
    private array $albumsInFlight = [];

    /** @var array<string, array{photo: Photo, at: float}>  photo id → cached detail */
    private array $photos = [];

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

        if (!$force && isset($this->albums[$libraryId]) && ($now - $this->albums[$libraryId]['at']) < $this->ttl) {
            return resolve($this->albums[$libraryId]['albums']);
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
                $this->albums[$libraryId] = ['albums' => $albums, 'at' => $now];
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

        if (!$force && isset($this->photos[$id]) && ($now - $this->photos[$id]['at']) < $this->ttl) {
            return resolve($this->photos[$id]['photo']);
        }

        if (isset($this->photosInFlight[$id])) {
            return $this->photosInFlight[$id];
        }

        /** @var Deferred<Photo> $deferred */
        $deferred = new Deferred();
        $this->photosInFlight[$id] = $deferred->promise();

        $this->api->photo($id)->then(
            function (Photo $photo) use ($id, $now, $deferred): void {
                $this->photos[$id] = ['photo' => $photo, 'at' => $now];
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
        $this->albums = [];
        $this->albumsInFlight = [];
        $this->photos = [];
        $this->photosInFlight = [];
    }
}

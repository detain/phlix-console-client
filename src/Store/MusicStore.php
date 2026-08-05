<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Album;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Caches the music album list (the server returns every album, with its full
 * track list, in one `/music/albums` call) over the {@see ApiClient} with a
 * short TTL, de-duplicating concurrent fetches.
 */
final class MusicStore
{
    /** Single-entry cache capacity. */
    private const CACHE_CAPACITY = 1;

    /** @var LruMap<string, array{albums: list<Album>, at: float}> */
    private LruMap $albums;

    /** @var PromiseInterface<list<Album>>|null  An album fetch in flight. */
    private ?PromiseInterface $inFlight = null;

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
        $this->albums = new LruMap(self::CACHE_CAPACITY);
    }

    /**
     * The full album list, TTL-cached. Concurrent calls share one fetch.
     *
     * @return PromiseInterface<list<Album>>
     */
    public function albums(bool $force = false): PromiseInterface
    {
        $key = 'albums';
        $now = ($this->clock)();

        if (!$force && ($entry = $this->albums->peek($key)) !== null && ($now - $entry['at']) < $this->ttl) {
            return resolve($this->albums->get($key)['albums']);
        }

        if ($this->inFlight !== null) {
            return $this->inFlight;
        }

        // Drive a Deferred so the in-flight guard is registered before the inner
        // request can settle (react may resolve synchronously) and cleared
        // exactly once on settle/reject.
        /** @var Deferred<list<Album>> $deferred */
        $deferred = new Deferred();
        $this->inFlight = $deferred->promise();

        $this->api->musicAlbums()->then(
            function (array $albums) use ($key, $now, $deferred): void {
                $this->albums->set($key, ['albums' => $albums, 'at' => $now]);
                $this->inFlight = null;
                $deferred->resolve($albums);
            },
            function (\Throwable $error) use ($deferred): void {
                $this->inFlight = null;
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    public function invalidate(): void
    {
        $this->albums->clear();
        $this->inFlight = null;
    }
}

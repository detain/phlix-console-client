<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Media;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Trickplay;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Util\LruMap;
use SugarCraft\Core\Util\Semaphore;
use SugarCraft\Image\DiskCache;

use function React\Promise\resolve;

/**
 * Caches trickplay sprite-preview data and limits concurrent fetches via a
 * semaphore, so simultaneous player opens don't hammer the server.
 *
 * The cache key is derived from the media ID only; trickplay is regenerated
 * when the media item changes, not when the sprite URL changes.
 */
final class TrickplayCache
{
    /** Entries the in-memory tier holds before the LRU victim is dropped. */
    private const MEMORY_CAPACITY = 512;

    /** In-memory cache of already-resolved trickplay data, keyed by media ID. */
    /** @var LruMap<mixed> — Trickplay values (phpstan cannot bind V on a static new()). */
    private LruMap $memory;

    /** @var array<string, PromiseInterface<Trickplay>>  media id → in-flight fetch */
    private array $inFlight = [];

    public function __construct(
        private readonly ApiClient $api,
        private readonly ?DiskCache $cache = null,
        private readonly ?Semaphore $semaphore = null,
    ) {
        $this->memory = LruMap::new(self::MEMORY_CAPACITY);
    }

    /**
     * Load trickplay data for $mediaId, using memory/disk cache when available
     * and fetching through the semaphore on cache miss.
     *
     * @return PromiseInterface<Trickplay>
     */
    public function load(string $mediaId): PromiseInterface
    {
        // Memory cache hit — instant resolve without I/O.
        $cached = $this->memory->get($mediaId);
        if ($cached instanceof Trickplay) {
            return resolve($cached);
        }

        $key = 'trickplay:' . $mediaId;

        // Disk cache hit.
        if ($this->cache !== null) {
            $blob = $this->cache->get($key);
            if ($blob !== null) {
                try {
                    $data = json_decode($blob, true, 8, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $data = null;
                }
                if (
                    is_array($data)
                    && array_key_exists('sprite_url', $data)
                    && array_key_exists('timeline_url', $data)
                ) {
                    /** @var array{sprite_url: ?string, timeline_url: ?string} $data */
                    // R-CONSOLE-09: persisted blobs re-enter through the SAME
                    // clamp as the wire — a cache file written before the cure
                    // (or tampered on disk) cannot smuggle foreign hosts back
                    // into the DTO or the memory tier.
                    $tp = Trickplay::fromArray($data, $this->api->baseUrl());
                    $this->memory->put($mediaId, $tp);

                    return resolve($tp);
                }
            }
        }

        // Coalesce concurrent misses for the same media ID (MediaStore idiom).
        if (isset($this->inFlight[$mediaId])) {
            return $this->inFlight[$mediaId];
        }

        /** @var Deferred<Trickplay> $deferred */
        $deferred = new Deferred();
        $this->inFlight[$mediaId] = $deferred->promise();

        // Cache miss — fetch via API (optionally through semaphore for concurrency control).
        $api = $this->api;
        $task = static fn (): PromiseInterface => $api->trickplay($mediaId);

        $promise = $this->semaphore !== null
            ? $this->semaphore->run($task)
            : $task();

        $promise->then(
            function (Trickplay $tp) use ($mediaId, $key, $deferred): void {
                $this->memory->put($mediaId, $tp);

                if ($this->cache !== null) {
                    try {
                        $this->cache->put($key, json_encode([
                            'sprite_url' => $tp->spriteUrl,
                            'timeline_url' => $tp->timelineUrl,
                        ], JSON_THROW_ON_ERROR));
                    } catch (\JsonException) {
                        // Unencodable value: skip the disk tier.
                    }
                }

                unset($this->inFlight[$mediaId]);
                $deferred->resolve($tp);
            },
            function (\Throwable $error) use ($mediaId, $deferred): void {
                unset($this->inFlight[$mediaId]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }
}

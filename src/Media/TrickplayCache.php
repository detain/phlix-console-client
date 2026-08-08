<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Media;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Trickplay;
use React\Promise\PromiseInterface;
use SugarCraft\Mosaic\DiskCache;

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
    /** In-memory cache of already-resolved trickplay data, keyed by media ID. */
    /** @var array<string, Trickplay> */
    private array $memory = [];

    /**
     * @param Semaphore<Trickplay>|null $semaphore
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly ?DiskCache $cache = null,
        private readonly ?Semaphore $semaphore = null,
    ) {
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
        if (isset($this->memory[$mediaId])) {
            return resolve($this->memory[$mediaId]);
        }

        $key = 'trickplay:' . $mediaId;

        // Disk cache hit.
        if ($this->cache !== null) {
            $blob = $this->cache->get($key);
            if ($blob !== null) {
                $data = json_decode($blob, true);
                if (
                    is_array($data)
                    && array_key_exists('sprite_url', $data)
                    && array_key_exists('timeline_url', $data)
                ) {
                    /** @var array{sprite_url: ?string, timeline_url: ?string} $data */
                    $tp = Trickplay::fromArray($data);
                    $this->memory[$mediaId] = $tp;

                    return resolve($tp);
                }
            }
        }

        // Cache miss — fetch via API (optionally through semaphore for concurrency control).
        $api = $this->api;
        $task = static fn (): PromiseInterface => $api->trickplay($mediaId);

        $promise = $this->semaphore !== null
            ? $this->semaphore->wrap($task)
            : $task();

        /** @return PromiseInterface<Trickplay> */
        return $promise->then(function (Trickplay $tp) use ($mediaId, $key): Trickplay {
            // Store in memory cache.
            $this->memory[$mediaId] = $tp;

            // Persist to disk cache if available.
            if ($this->cache !== null) {
                $encoded = json_encode([
                    'sprite_url' => $tp->spriteUrl,
                    'timeline_url' => $tp->timelineUrl,
                ]);
                if (is_string($encoded)) {
                    $this->cache->put($key, $encoded);
                }
            }

            return $tp;
        });
    }
}

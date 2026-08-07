<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Library;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Caches the library list over the {@see ApiClient} with a short TTL, so the
 * browse home doesn't refetch on every redraw.
 */
final class LibrariesStore
{
    /** Single-entry cache capacity. */
    private const CACHE_CAPACITY = 1;

    /** @var LruMap|null */
    private ?LruMap $cache;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock  Time source (seconds); injectable for tests.
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly float $ttl = 60.0,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->cache = new LruMap(self::CACHE_CAPACITY);
    }

    /**
     * Return the libraries, fetching when the cache is empty or stale.
     *
     * @return PromiseInterface<list<Library>>
     */
    public function all(bool $force = false): PromiseInterface
    {
        $key = 'libraries';
        $now = ($this->clock)();

        // Reinitialize cache if it was invalidated (set to null).
        if ($this->cache === null) {
            $this->cache = new LruMap(self::CACHE_CAPACITY);
        }

        $entry = $this->cache->peek($key);
        /** @var array{cache: list<Library>, at: float}|null $entry */
        if (!$force && $entry !== null && ($now - $entry['at']) < $this->ttl) {
            $cached = $this->cache->get($key);
            /** @var array{cache: list<Library>, at: float} $cached */
            return resolve($cached['cache']);
        }

        return $this->api->libraries()->then(function (array $libraries) use ($key, $now): array {
            $this->cache->set($key, ['cache' => $libraries, 'at' => $now]);

            return $libraries;
        });
    }

    /** @return list<Library>|null */
    public function cached(): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        /** @var array{cache: list<Library>, at: float}|null $entry */
        $entry = $this->cache->peek('libraries');

        return $entry !== null ? $entry['cache'] : null;
    }

    public function invalidate(): void
    {
        $this->cache = null;
    }
}

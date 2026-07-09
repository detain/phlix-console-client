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
    /** @var list<Library>|null */
    private ?array $cache = null;
    private float $fetchedAt = 0.0;

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
    }

    /**
     * Return the libraries, fetching when the cache is empty or stale.
     *
     * @return PromiseInterface<list<Library>>
     */
    public function all(bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();
        if (!$force && $this->cache !== null && ($now - $this->fetchedAt) < $this->ttl) {
            return resolve($this->cache);
        }

        return $this->api->libraries()->then(function (array $libraries) use ($now): array {
            $this->cache = $libraries;
            $this->fetchedAt = $now;

            return $libraries;
        });
    }

    /** @return list<Library>|null */
    public function cached(): ?array
    {
        return $this->cache;
    }

    public function invalidate(): void
    {
        $this->cache = null;
        $this->fetchedAt = 0.0;
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Caches the available filter facets (genres) for a library from
 * {@see ApiClient::facets()} with a short TTL and de-duplicates concurrent
 * fetches via an in-flight Deferred.
 */
final class FacetsStore
{
    /** @var array{facets: array<string, list<string>>, at: float}|null */
    private ?array $cache = null;

    /** @var PromiseInterface<array<string, list<string>>>|null */
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
    }

    public function api(): ApiClient
    {
        return $this->api;
    }

    /**
     * Fetch the facets for a library, serving from cache if fresh or
     * coalescing concurrent requests into a single HTTP call.
     *
     * @return PromiseInterface<array<string, list<string>>>
     */
    public function facets(string $libraryId, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        if (!$force && $this->cache !== null && ($now - $this->cache['at']) < $this->ttl) {
            return resolve($this->cache['facets']);
        }

        if ($this->inFlight !== null) {
            return $this->inFlight;
        }

        /** @var Deferred<array<string, list<string>>> $deferred */
        $deferred = new Deferred();
        $this->inFlight = $deferred->promise();

        $this->api->facets($libraryId)->then(
            function (array $facets) use ($now, $deferred): void {
                $this->cache = ['facets' => $facets, 'at' => $now];
                $this->inFlight = null;
                $deferred->resolve($facets);
            },
            function (\Throwable $error) use ($deferred): void {
                $this->inFlight = null;
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * Invalidate the cached facets so the next request fetches fresh data.
     */
    public function invalidate(): void
    {
        $this->cache = null;
        $this->inFlight = null;
    }
}

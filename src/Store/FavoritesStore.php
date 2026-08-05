<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\MediaPage;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Caches the user's favorites page from {@see ApiClient::favorites()} with a
 * short TTL and de-duplicates concurrent fetches via an in-flight Deferred.
 */
final class FavoritesStore
{
    /** @var array{page: MediaPage, at: float}|null */
    private ?array $cache = null;

    /** @var PromiseInterface<MediaPage>|null */
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
     * Fetch the favorites page, serving from cache if fresh or coalescing
     * concurrent requests into a single HTTP call.
     *
     * @return PromiseInterface<MediaPage>
     */
    public function page(bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        if (!$force && $this->cache !== null && ($now - $this->cache['at']) < $this->ttl) {
            return resolve($this->cache['page']);
        }

        if ($this->inFlight !== null) {
            return $this->inFlight;
        }

        /** @var Deferred<MediaPage> $deferred */
        $deferred = new Deferred();
        $this->inFlight = $deferred->promise();

        $this->api->favorites()->then(
            function (MediaPage $page) use ($now, $deferred): void {
                $this->cache = ['page' => $page, 'at' => $now];
                $this->inFlight = null;
                $deferred->resolve($page);
            },
            function (\Throwable $error) use ($deferred): void {
                $this->inFlight = null;
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * Invalidate the cached favorites page so the next request fetches fresh data.
     */
    public function invalidate(): void
    {
        $this->cache = null;
        $this->inFlight = null;
    }
}

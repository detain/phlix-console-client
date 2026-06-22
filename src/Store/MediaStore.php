<?php

declare(strict_types=1);

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\MediaQuery;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Caches media pages (keyed by query + paging) and the continue-watching list
 * over the {@see ApiClient} with a short TTL.
 */
final class MediaStore
{
    /** @var array<string, array{page: MediaPage, at: float}> */
    private array $pages = [];

    /** @var array{items: list<ContinueWatchingItem>, at: float}|null */
    private ?array $continue = null;

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
     * @return PromiseInterface<MediaPage>
     */
    public function page(MediaQuery $query, bool $force = false): PromiseInterface
    {
        $key = $query->cacheKey() . ':' . $query->offset . ':' . $query->limit;
        $now = ($this->clock)();

        if (!$force && isset($this->pages[$key]) && ($now - $this->pages[$key]['at']) < $this->ttl) {
            return resolve($this->pages[$key]['page']);
        }

        return $this->api->media($query)->then(function (MediaPage $page) use ($key, $now): MediaPage {
            $this->pages[$key] = ['page' => $page, 'at' => $now];

            return $page;
        });
    }

    /**
     * @return PromiseInterface<list<ContinueWatchingItem>>
     */
    public function continueWatching(bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        if (!$force && $this->continue !== null && ($now - $this->continue['at']) < $this->ttl) {
            return resolve($this->continue['items']);
        }

        return $this->api->continueWatching()->then(function (array $items) use ($now): array {
            $this->continue = ['items' => $items, 'at' => $now];

            return $items;
        });
    }

    public function invalidate(): void
    {
        $this->pages = [];
        $this->continue = null;
    }
}

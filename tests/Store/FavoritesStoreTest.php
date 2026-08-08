<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Store\FavoritesStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class FavoritesStoreTest extends TestCase
{
    /**
     * @return array{items: list<array{id:string,name:string,type:string}>,total:int,offset:int,limit:int}
     */
    private function favoritesPageResponse(array $items, int $total = 0, int $limit = 100, int $offset = 0): array
    {
        return [
            'items' => $items,
            'total' => $total > 0 ? $total : count($items),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function testPageFetchesFromApiAndCaches(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())->json(200, $this->favoritesPageResponse([
            ['id' => 'm1', 'name' => 'Movie One', 'type' => 'movie'],
            ['id' => 'm2', 'name' => 'Movie Two', 'type' => 'movie'],
        ], 2));
        $store = new FavoritesStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->page());
        self::assertInstanceOf(MediaPage::class, $first);
        self::assertSame(2, $first->total);
        self::assertCount(2, $first->items);
        self::assertSame(1, $transport->requestCount());

        // Within TTL → cached, no second request.
        $now = 1030.0;
        $cached = $this->await($store->page());
        self::assertCount(2, $cached->items);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testPageRefetchesAfterTtlExpiry(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $firstPage = $this->favoritesPageResponse([['id' => 'm1', 'name' => 'First', 'type' => 'movie']], 1);
        $secondPage = $this->favoritesPageResponse([['id' => 'm1', 'name' => 'Second', 'type' => 'movie']], 1);
        $transport = (new FakeTransport())
            ->json(200, $firstPage)
            ->json(200, $secondPage);
        $store = new FavoritesStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->page());
        self::assertSame('First', $first->items[0]->name);
        self::assertSame(1, $transport->requestCount());

        // Past the TTL → refetch.
        $now = 1070.0;
        $second = $this->await($store->page());
        self::assertSame('Second', $second->items[0]->name);
        self::assertSame(2, $transport->requestCount(), 'refetched after TTL expiry');
    }

    public function testPageCoalescesConcurrentRequests(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new FavoritesStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->page();
        $second = $store->page();

        // The in-flight promise is shared, so only one HTTP request is made
        // even though two page() calls were made concurrently.
        self::assertSame(1, $transport->requestCount(), 'only one underlying request for concurrent calls');
    }

    public function testForceRefreshBypassesCache(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $firstPage = $this->favoritesPageResponse([['id' => 'm1', 'name' => 'First', 'type' => 'movie']], 1);
        $secondPage = $this->favoritesPageResponse([['id' => 'm1', 'name' => 'Second', 'type' => 'movie']], 1);
        $transport = (new FakeTransport())
            ->json(200, $firstPage)
            ->json(200, $secondPage);
        $store = new FavoritesStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->page());
        self::assertSame('First', $first->items[0]->name);

        // Force refresh → new request even within TTL.
        $second = $this->await($store->page(force: true));
        self::assertSame('Second', $second->items[0]->name);
        self::assertSame(2, $transport->requestCount(), 'force refresh bypasses cache');
    }

    public function testInvalidateClearsCacheAndInFlight(): void
    {
        $firstPage = $this->favoritesPageResponse([['id' => 'm1', 'name' => 'First', 'type' => 'movie']], 1);
        $secondPage = $this->favoritesPageResponse([['id' => 'm1', 'name' => 'Second', 'type' => 'movie']], 1);
        $transport = (new FakeTransport())
            ->json(200, $firstPage)
            ->json(200, $secondPage);
        $store = new FavoritesStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $this->await($store->page());
        self::assertSame('First', $first->items[0]->name);
        self::assertSame(1, $transport->requestCount());

        $store->invalidate();

        $second = $this->await($store->page());
        self::assertSame('Second', $second->items[0]->name);
        self::assertSame(2, $transport->requestCount(), 'invalidate forces a refetch');
    }

    public function testFailedFetchClearsInFlightSoRetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->favoritesPageResponse([['id' => 'm1', 'name' => 'First', 'type' => 'movie']], 1));
        $store = new FavoritesStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->page());
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $page = $this->await($store->page());
        self::assertNotNull($page, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($v) use (&$state): void {
                $state['value'] = $v;
                $state['done'] = true;
                Loop::stop();
            },
            function ($e) use (&$state): void {
                $state['error'] = $e;
                $state['done'] = true;
                Loop::stop();
            },
        );
        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }
        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}

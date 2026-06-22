<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Store\MediaRange;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class MediaStoreTest extends TestCase
{
    private function mediaResponse(string $id): array
    {
        return ['items' => [['id' => $id, 'name' => 'Item ' . $id, 'type' => 'movie']], 'total' => 1, 'limit' => 18, 'offset' => 0];
    }

    public function testPageCachesPerQueryWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())
            ->json(200, $this->mediaResponse('a'))
            ->json(200, $this->mediaResponse('b'));
        $store = new MediaStore(new ApiClient('https://srv', $transport), 60.0, $clock);
        $query = MediaQuery::forLibrary('lib-1', limit: 18);

        $first = $this->await($store->page($query));
        self::assertInstanceOf(MediaPage::class, $first);
        self::assertSame(1, $transport->requestCount());

        // Same query within TTL → cache.
        $now = 1030.0;
        $this->await($store->page($query));
        self::assertSame(1, $transport->requestCount(), 'cached');

        // A different library is a different cache key → fetch.
        $this->await($store->page(MediaQuery::forLibrary('lib-2', limit: 18)));
        self::assertSame(2, $transport->requestCount());
    }

    public function testDifferentOffsetIsADifferentCacheEntry(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->mediaResponse('a'))
            ->json(200, $this->mediaResponse('b'));
        $store = new MediaStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);
        $query = MediaQuery::forLibrary('lib-1', limit: 18);

        $this->await($store->page($query));
        $this->await($store->page($query->withOffset(18)));

        self::assertSame(2, $transport->requestCount(), 'pages of the same query are cached separately');
    }

    public function testContinueWatchingCachesAndInvalidate(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())
            ->json(200, ['items' => [['media_item_id' => 'm1', 'name' => 'Show', 'position_ticks' => 5, 'duration_ticks' => 10]]])
            ->json(200, ['items' => []]);
        $store = new MediaStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $items = $this->await($store->continueWatching());
        self::assertContainsOnlyInstancesOf(ContinueWatchingItem::class, $items);
        self::assertSame(1, $transport->requestCount());

        $now = 1010.0;
        $this->await($store->continueWatching());
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');

        $store->invalidate();
        $this->await($store->continueWatching());
        self::assertSame(2, $transport->requestCount(), 'invalidate forces a refetch');
    }

    /** A `/media` page response with items whose id == their absolute index. */
    private function pageResponse(int $offset, int $count, int $total, int $limit): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = ['id' => (string) ($offset + $i), 'name' => 'Item ' . ($offset + $i), 'type' => 'movie'];
        }

        return ['items' => $items, 'total' => $total, 'offset' => $offset, 'limit' => $limit];
    }

    public function testEnsureRangeFetchesCoveringPagesAndSplicesAtAbsoluteIndex(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pageResponse(10, 10, 50, 10))
            ->json(200, $this->pageResponse(20, 10, 50, 10));
        $store = new MediaStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);
        $query = MediaQuery::forLibrary('lib', limit: 10);

        $range = $this->await($store->ensureRange($query, 12, 23));

        self::assertInstanceOf(MediaRange::class, $range);
        self::assertSame(50, $range->total);
        self::assertSame(range(12, 23), array_keys($range->items), 'items keyed by absolute index, clipped to the window');
        self::assertSame('12', $range->items[12]->id);
        self::assertSame('23', $range->items[23]->id);
        self::assertSame(2, $transport->requestCount(), 'two covering pages fetched');
        self::assertStringContainsString('offset=10', $transport->requestAt(0)['url']);
        self::assertStringContainsString('offset=20', $transport->requestAt(1)['url']);
    }

    public function testEnsureRangeWithinASinglePage(): void
    {
        $transport = (new FakeTransport())->json(200, $this->pageResponse(0, 10, 50, 10));
        $store = new MediaStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $range = $this->await($store->ensureRange(MediaQuery::forLibrary('lib', limit: 10), 2, 5));

        self::assertSame([2, 3, 4, 5], array_keys($range->items));
        self::assertSame(1, $transport->requestCount());
    }

    public function testEnsureRangeEmptyWindowFetchesNothing(): void
    {
        $transport = new FakeTransport();
        $store = new MediaStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        // An empty grid reports visibleRange() === [0, -1].
        $range = $this->await($store->ensureRange(MediaQuery::forLibrary('lib', limit: 10), 0, -1));

        self::assertTrue($range->isEmpty());
        self::assertSame(0, $transport->requestCount(), 'no fetch for an empty window');
    }

    public function testEnsureRangeReusesCachedPages(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pageResponse(0, 10, 50, 10))
            ->json(200, $this->pageResponse(10, 10, 50, 10));
        $store = new MediaStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);
        $query = MediaQuery::forLibrary('lib', limit: 10);

        $this->await($store->ensureRange($query, 0, 5));   // fetches page@0
        $this->await($store->ensureRange($query, 8, 12));  // page@0 cached → only page@10

        self::assertSame(2, $transport->requestCount(), 'page@0 reused from cache');
    }

    public function testConcurrentPageFetchesAreDeduplicated(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new MediaStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);
        $query = MediaQuery::forLibrary('lib', limit: 10);

        $first = $store->page($query);
        $second = $store->page($query);

        self::assertSame($first, $second, 'a concurrent fetch of the same page shares the in-flight promise');
        self::assertSame(1, $transport->requestCount(), 'only one underlying request');
    }

    public function testFailedPageClearsInFlightSoARetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->pageResponse(0, 1, 1, 10));
        $store = new MediaStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);
        $query = MediaQuery::forLibrary('lib', limit: 10);

        $error = null;
        try {
            $this->await($store->page($query));
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $page = $this->await($store->page($query));
        self::assertInstanceOf(MediaPage::class, $page, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    public function testLetterIndexCachesWithinTtlAndInvalidates(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())
            ->json(200, ['letters' => [['letter' => 'A', 'offset' => 0, 'count' => 3]], 'total' => 3])
            ->json(200, ['letters' => [], 'total' => 0]);
        $store = new MediaStore(new ApiClient('https://srv', $transport), 60.0, $clock);
        $query = MediaQuery::forLibrary('lib');

        $first = $this->await($store->letterIndex($query));
        self::assertSame(3, $first->total);
        self::assertSame(1, $transport->requestCount());

        $now = 1030.0;
        $this->await($store->letterIndex($query));
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');

        $store->invalidate();
        $this->await($store->letterIndex($query));
        self::assertSame(2, $transport->requestCount(), 'invalidate forces a refetch');
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

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\MediaQuery;
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

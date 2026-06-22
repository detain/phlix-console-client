<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class LibrariesStoreTest extends TestCase
{
    public function testFetchesThenServesFromCacheWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())
            ->json(200, ['libraries' => [['id' => 'l1', 'name' => 'Movies', 'type' => 'movie']]])
            ->json(200, ['libraries' => [
                ['id' => 'l1', 'name' => 'Movies', 'type' => 'movie'],
                ['id' => 'l2', 'name' => 'TV', 'type' => 'series'],
            ]]);
        $store = new LibrariesStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        self::assertCount(1, $this->await($store->all()));
        self::assertSame(1, $transport->requestCount());

        // Within TTL → cache, no new request.
        $now = 1030.0;
        self::assertCount(1, $this->await($store->all()));
        self::assertSame(1, $transport->requestCount(), 'served from cache');

        // Past TTL → refetch.
        $now = 1070.0;
        self::assertCount(2, $this->await($store->all()));
        self::assertSame(2, $transport->requestCount());
    }

    public function testForceRefetchesAndInvalidateClears(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['libraries' => [['id' => 'l1', 'name' => 'A', 'type' => 'movie']]])
            ->json(200, ['libraries' => [['id' => 'l1', 'name' => 'A', 'type' => 'movie']]]);
        $store = new LibrariesStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->all());
        self::assertNotNull($store->cached());

        $this->await($store->all(force: true));
        self::assertSame(2, $transport->requestCount(), 'force bypasses cache');

        $store->invalidate();
        self::assertNull($store->cached());
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

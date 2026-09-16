<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\Media;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Trickplay;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Media\TrickplayCache;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

/**
 * The memory tier of {@see TrickplayCache}: a repeated load must not re-hit
 * the API, and the tier must be bounded — an opened-title marathon cannot
 * grow it past the LRU capacity (F-6).
 */
final class TrickplayCacheTest extends TestCase
{
    private const BASE = 'https://srv';

    /** Matches TrickplayCache::MEMORY_CAPACITY. */
    private const CAPACITY = 512;

    public function testRepeatedLoadOfOneMediaIdHitsMemoryNotTheApi(): void
    {
        $transport = (new FakeTransport())->json(200, ['sprite_url' => '/sp.jpg', 'timeline_url' => '/tl.json']);
        $cache = $this->cache($transport);

        $first = $this->await($cache->load('m1'));
        $second = $this->await($cache->load('m1'));

        self::assertInstanceOf(Trickplay::class, $first);
        self::assertSame($first, $second, 'the memory tier returns the same resolved DTO');
        self::assertSame('/sp.jpg', $first->spriteUrl);
        self::assertSame(1, $transport->requestCount(), 'the second load must not re-fetch');
    }

    public function testMemoryTierEvictsLeastRecentlyUsedBeyondCapacity(): void
    {
        $transport = new FakeTransport();
        $cache = $this->cache($transport);

        foreach (range(1, self::CAPACITY + 1) as $i) {
            $this->await($cache->load('m' . $i));
        }

        self::assertSame(self::CAPACITY + 1, $transport->requestCount(), 'every distinct id fetched once');

        // The most recent entry is still warm: no new request for it.
        $this->await($cache->load('m' . (self::CAPACITY + 1)));
        self::assertSame(self::CAPACITY + 1, $transport->requestCount(), 'the newest id stays cached');

        // The capacity bound evicted the oldest entry, so it re-fetches.
        $this->await($cache->load('m1'));
        self::assertSame(
            self::CAPACITY + 2,
            $transport->requestCount(),
            'the LRU victim re-fetches — the tier is bounded',
        );
    }

    private function cache(FakeTransport $transport): TrickplayCache
    {
        $api = new ApiClient(self::BASE, $transport);
        $api->setToken(new TokenBundle('t', 'r'));

        return new TrickplayCache($api);
    }

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($value) use (&$state): void {
                $state['value'] = $value;
                $state['done'] = true;
                Loop::stop();
            },
            function ($error) use (&$state): void {
                $state['error'] = $error;
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

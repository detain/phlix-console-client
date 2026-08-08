<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Store\FacetsStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class FacetsStoreTest extends TestCase
{
    public function testFacetsCachesWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())
            ->json(200, ['genres' => ['Action', 'Comedy']])
            ->json(200, ['genres' => ['Action', 'Comedy']]);
        $store = new FacetsStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->facets('lib-movies'));
        self::assertSame(['genres' => ['Action', 'Comedy']], $first);
        self::assertSame(1, $transport->requestCount());

        $now = 1030.0;
        $second = $this->await($store->facets('lib-movies'));
        self::assertSame(['genres' => ['Action', 'Comedy']], $second);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testFacetsForceBypassesCache(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['genres' => ['Action']])
            ->json(200, ['genres' => ['Drama']]);
        $store = new FacetsStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $this->await($store->facets('lib-movies'));
        self::assertSame(['genres' => ['Action']], $first);

        $second = $this->await($store->facets('lib-movies', force: true));
        self::assertSame(['genres' => ['Drama']], $second);
        self::assertSame(2, $transport->requestCount(), 'force refetches even within TTL');
    }

    public function testFacetsRefetchesAfterTtlExpiry(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())
            ->json(200, ['genres' => ['Action']])
            ->json(200, ['genres' => ['Drama']]);
        $store = new FacetsStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->facets('lib-movies'));
        self::assertSame(['genres' => ['Action']], $first);

        $now = 1070.0; // past the 60s TTL
        $second = $this->await($store->facets('lib-movies'));
        self::assertSame(['genres' => ['Drama']], $second);
        self::assertSame(2, $transport->requestCount(), 'refetched after TTL expiry');
    }

    public function testConcurrentFacetsFetchesAreDeduplicated(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new FacetsStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->facets('lib-movies');
        $second = $store->facets('lib-movies');

        self::assertSame($first, $second, 'a concurrent fetch of the same library shares the in-flight promise');
        self::assertSame(1, $transport->requestCount(), 'only one underlying request');
    }

    public function testFailedFacetsClearsInFlightSoRetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, ['genres' => ['Action']]);
        $store = new FacetsStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->facets('lib-movies'));
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $facets = $this->await($store->facets('lib-movies'));
        self::assertSame(['genres' => ['Action']], $facets, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    public function testInvalidateDropsCachedFacets(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['genres' => ['Action']])
            ->json(200, ['genres' => ['Drama']]);
        $store = new FacetsStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->facets('lib-movies'));
        $store->invalidate();
        $this->await($store->facets('lib-movies'));

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

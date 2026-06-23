<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Store\MusicStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class MusicStoreTest extends TestCase
{
    /** The `/music/albums` envelope: `{ "albums": [ … ] }`. */
    private function albumsResponse(string $name = 'Abbey Road'): array
    {
        return ['albums' => [
            [
                'name' => $name,
                'artist' => 'The Beatles',
                'year' => 1969,
                'track_count' => 1,
                'tracks' => [
                    ['id' => 't1', 'name' => 'x', 'metadata' => ['title' => 'Come Together', 'duration_secs' => 259]],
                ],
            ],
        ]];
    }

    public function testAlbumsFetchesAndCachesWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        // Only ONE queued response: a second uncached call would exhaust the
        // queue (and fall back to an empty `{}` → 0 albums), so the cache hit is
        // proven by the album list still being present and no new request.
        $transport = (new FakeTransport())->json(200, $this->albumsResponse());
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $albums = $this->await($store->albums());
        self::assertContainsOnlyInstancesOf(Album::class, $albums);
        self::assertCount(1, $albums);
        self::assertSame('Abbey Road', $albums[0]->name);
        self::assertSame(1, $transport->requestCount());
        self::assertStringEndsWith('/api/v1/music/albums', $transport->requestAt(0)['url']);

        // Within TTL → cached, no second request.
        $now = 1030.0;
        $cached = $this->await($store->albums());
        self::assertCount(1, $cached);
        self::assertSame('Abbey Road', $cached[0]->name);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testAlbumsRefetchesAfterTtlExpiry(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())
            ->json(200, $this->albumsResponse('First'))
            ->json(200, $this->albumsResponse('Second'));
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->albums());
        self::assertSame('First', $first[0]->name);
        self::assertSame(1, $transport->requestCount());

        // Past the TTL → refetch.
        $now = 1070.0;
        $second = $this->await($store->albums());
        self::assertSame('Second', $second[0]->name);
        self::assertSame(2, $transport->requestCount(), 'refetched after TTL expiry');
    }

    public function testAlbumsForceBypassesTheCache(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->albumsResponse('First'))
            ->json(200, $this->albumsResponse('Second'));
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->albums());
        $forced = $this->await($store->albums(force: true));

        self::assertSame('Second', $forced[0]->name);
        self::assertSame(2, $transport->requestCount(), 'force refetches even within TTL');
    }

    public function testConcurrentAlbumFetchesAreDeduplicated(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->albums();
        $second = $store->albums();

        self::assertSame($first, $second, 'a concurrent fetch shares the in-flight promise');
        self::assertSame(1, $transport->requestCount(), 'only one underlying request');
    }

    public function testFailedFetchClearsInFlightSoARetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->albumsResponse());
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->albums());
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $albums = $this->await($store->albums());
        self::assertContainsOnlyInstancesOf(Album::class, $albums, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    public function testInvalidateForcesARefetch(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->albumsResponse('First'))
            ->json(200, $this->albumsResponse('Second'));
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $this->await($store->albums());
        self::assertSame('First', $first[0]->name);

        $store->invalidate();

        $second = $this->await($store->albums());
        self::assertSame('Second', $second[0]->name);
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

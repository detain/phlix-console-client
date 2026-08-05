<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Store\MusicRange;
use Phlix\Console\Store\MusicStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class MusicStoreTest extends TestCase
{
    /**
     * The `/music/albums` paged envelope: `{ "albums": [ … ], "total": N, "limit": N, "offset": N }`.
     *
     * @param list<array{name:string,artist:?string,year:?int,track_count:int,tracks:list<array>>} $albums
     */
    private function albumPageResponse(array $albums, int $total = 0, int $limit = 100, int $offset = 0): array
    {
        return [
            'albums' => $albums,
            'total' => $total > 0 ? $total : count($albums),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    private function singleAlbumResponse(string $name = 'Abbey Road'): array
    {
        return $this->albumPageResponse([[
            'name' => $name,
            'artist' => 'The Beatles',
            'year' => 1969,
            'track_count' => 1,
            'tracks' => [
                ['id' => 't1', 'name' => 'x', 'metadata' => ['title' => 'Come Together', 'duration_secs' => 259]],
            ],
        ]], 1, 100, 0);
    }

    public function testEnsureRangeFetchesCorrectLimitAndOffset(): void
    {
        $transport = (new FakeTransport())->json(200, $this->singleAlbumResponse());
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->ensureRange(0, 24, 100));

        self::assertSame(1, $transport->requestCount());
        $url = $transport->requestAt(0)['url'];
        self::assertStringContainsString('limit=100', $url);
        self::assertStringContainsString('offset=0', $url);
    }

    public function testEnsureRangeFetchesWithOffsetForNonZeroStart(): void
    {
        $transport = (new FakeTransport())->json(200, $this->albumPageResponse([], 500, 100, 100));
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->ensureRange(100, 124, 100));

        self::assertSame(1, $transport->requestCount());
        $url = $transport->requestAt(0)['url'];
        self::assertStringContainsString('limit=100', $url);
        self::assertStringContainsString('offset=100', $url);
    }

    public function testEnsureRangeCachesPagesWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())->json(200, $this->singleAlbumResponse());
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->ensureRange(0, 24, 100));
        self::assertSame(1, $first->total);
        self::assertCount(1, $first->albums);
        self::assertSame(1, $transport->requestCount());

        // Within TTL → cached, no second request.
        $now = 1030.0;
        $cached = $this->await($store->ensureRange(0, 24, 100));
        self::assertCount(1, $cached->albums);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testEnsureRangeRefetchesAfterTtlExpiry(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        // Use the same page response for both fetches — after TTL expiry the
        // first cached entry is dropped and a new request is made.
        $firstPage = $this->albumPageResponse([[
            'name' => 'First',
            'artist' => 'Band',
            'year' => 2020,
            'track_count' => 1,
            'tracks' => [],
        ]], 1, 100, 0);
        $secondPage = $this->albumPageResponse([[
            'name' => 'Second',
            'artist' => 'Band',
            'year' => 2021,
            'track_count' => 2,
            'tracks' => [],
        ]], 1, 100, 0);
        $transport = (new FakeTransport())
            ->json(200, $firstPage)
            ->json(200, $secondPage);
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->ensureRange(0, 0, 100));
        self::assertSame('First', $first->albums[0]->name);
        self::assertSame(1, $transport->requestCount());

        // Past the TTL → refetch (the same page offset is fetched again).
        $now = 1070.0;
        $second = $this->await($store->ensureRange(0, 0, 100));
        self::assertSame('Second', $second->albums[0]->name);
        self::assertSame(2, $transport->requestCount(), 'refetched after TTL expiry');
    }

    public function testEnsureRangeDeduplicatesConcurrentFetchesForSameOffset(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->ensureRange(0, 24, 100);
        $second = $store->ensureRange(0, 24, 100);

        // The inner page promises are shared via inFlight, so only one HTTP
        // request is made even though two ensureRange() calls were made.
        self::assertSame(1, $transport->requestCount(), 'only one underlying request per offset');
    }

    public function testEnsureRangeFetchesMultiplePagesForWideRange(): void
    {
        // 5000 albums, limit 100 per page, requesting range 150-249 needs pages 100 and 200.
        $page100 = $this->albumPageResponse([], 5000, 100, 100);
        $page200 = $this->albumPageResponse([], 5000, 100, 200);
        $transport = (new FakeTransport())
            ->json(200, $page100)
            ->json(200, $page200);
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $range = $this->await($store->ensureRange(150, 249, 100));

        // Should have fetched exactly 2 pages (offsets 100 and 200).
        self::assertSame(2, $transport->requestCount());
        // First page fetched at offset 100.
        self::assertStringContainsString('offset=100', $transport->requestAt(0)['url']);
        // Second page fetched at offset 200.
        self::assertStringContainsString('offset=200', $transport->requestAt(1)['url']);
    }

    public function testFailedFetchClearsInFlightSoRetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->singleAlbumResponse());
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->ensureRange(0, 24, 100));
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $range = $this->await($store->ensureRange(0, 24, 100));
        self::assertNotNull($range, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    public function testInvalidateForcesRefetch(): void
    {
        $firstPage = $this->albumPageResponse([[
            'name' => 'First',
            'artist' => 'Band',
            'year' => 2020,
            'track_count' => 1,
            'tracks' => [],
        ]], 1, 100, 0);
        $secondPage = $this->albumPageResponse([[
            'name' => 'Second',
            'artist' => 'Band',
            'year' => 2021,
            'track_count' => 2,
            'tracks' => [],
        ]], 1, 100, 0);
        $transport = (new FakeTransport())
            ->json(200, $firstPage)
            ->json(200, $secondPage);
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $this->await($store->ensureRange(0, 0, 100));
        self::assertSame('First', $first->albums[0]->name);

        $store->invalidate();

        $second = $this->await($store->ensureRange(0, 0, 100));
        self::assertSame('Second', $second->albums[0]->name);
        self::assertSame(2, $transport->requestCount(), 'invalidate forces a refetch');
    }

    public function test5000AlbumFixtureTriggersOne100RowFetchPerPageBoundary(): void
    {
        // Simulate a 5000-album library with single-album pages.
        $pages = [];
        for ($i = 0; $i < 5000; $i++) {
            $pages[] = $this->albumPageResponse([[
                'name' => "Album $i",
                'artist' => 'Artist',
                'year' => 2000 + ($i % 30),
                'track_count' => ($i % 10) + 1,
                'tracks' => [],
            ]], 5000, 1, $i);
        }

        $transport = new FakeTransport();
        foreach ($pages as $page) {
            $transport->json(200, $page);
        }
        $store = new MusicStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        // Request just the first 10 items (should only fetch pages 0-9 = 10 pages).
        $range = $this->await($store->ensureRange(0, 9, 1));

        // Should have fetched exactly 10 pages (offsets 0-9), not all 5000.
        self::assertLessThanOrEqual(10, $transport->requestCount(), 'scrolling a 5000-album library should fetch only visible pages, not the whole library');
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

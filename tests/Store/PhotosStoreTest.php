<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Photo;
use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\Store\PhotosStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class PhotosStoreTest extends TestCase
{
    /** A `/photo/albums` envelope: `{albums:[…]}` with one dated album. */
    private function albumsResponse(string $date = '2023-11-14'): array
    {
        return ['albums' => [
            [
                'id' => md5($date),
                'date' => $date,
                'photo_count' => 1,
                'cover_photo' => ['id' => 'p1', 'name' => 'a.jpg', 'thumbnail_url' => '/t/p1', 'full_url' => '/f/p1'],
                'photos' => [
                    ['id' => 'p1', 'name' => 'a.jpg', 'thumbnail_url' => '/t/p1', 'full_url' => '/f/p1'],
                ],
            ],
        ]];
    }

    /** A `/photo/photos/{id}` envelope: `{photo:{…exif}}`. */
    private function photoResponse(string $make = 'Canon'): array
    {
        return ['photo' => [
            'id' => 'p1',
            'name' => 'a.jpg',
            'path' => '/photos/a.jpg',
            'metadata' => ['camera_make' => $make],
            'exif' => ['camera_make' => $make, 'iso' => 400],
            'thumbnail_url' => '/api/v1/photo/photos/p1/thumbnail?sig=abc',
            'full_url' => '/api/v1/photo/photos/p1/full?sig=def',
        ]];
    }

    // ---- albums() ------------------------------------------------------

    public function testAlbumsFetchesAndCachesWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        // Only ONE queued response: a second uncached call would exhaust the
        // queue (and fall back to an empty `{}` → 0 albums), so the cache hit is
        // proven by the albums still being present and no new request.
        $transport = (new FakeTransport())->json(200, $this->albumsResponse());
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $albums = $this->await($store->albums('lib-1'));
        self::assertContainsOnlyInstancesOf(PhotoAlbum::class, $albums);
        self::assertCount(1, $albums);
        self::assertSame('2023-11-14', $albums[0]->date);
        self::assertSame(1, $transport->requestCount());
        self::assertStringContainsString('/api/v1/photo/albums?', $transport->requestAt(0)['url']);
        self::assertStringContainsString('library_id=lib-1', $transport->requestAt(0)['url']);

        // Within TTL → cached, no second request.
        $now = 1030.0;
        $cached = $this->await($store->albums('lib-1'));
        self::assertCount(1, $cached);
        self::assertSame('2023-11-14', $cached[0]->date);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testAlbumsRefetchesAfterTtlExpiry(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())
            ->json(200, $this->albumsResponse('2023-01-01'))
            ->json(200, $this->albumsResponse('2023-02-02'));
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->albums('lib-1'));
        self::assertSame('2023-01-01', $first[0]->date);
        self::assertSame(1, $transport->requestCount());

        // Past the TTL → refetch.
        $now = 1070.0;
        $second = $this->await($store->albums('lib-1'));
        self::assertSame('2023-02-02', $second[0]->date);
        self::assertSame(2, $transport->requestCount(), 'refetched after TTL expiry');
    }

    public function testAlbumsForceBypassesTheCache(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->albumsResponse('2023-01-01'))
            ->json(200, $this->albumsResponse('2023-02-02'));
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->albums('lib-1'));
        $forced = $this->await($store->albums('lib-1', force: true));

        self::assertSame('2023-02-02', $forced[0]->date);
        self::assertSame(2, $transport->requestCount(), 'force refetches even within TTL');
    }

    public function testAlbumsAreKeyedByLibraryId(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->albumsResponse('2023-01-01'))
            ->json(200, $this->albumsResponse('2023-02-02'));
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $this->await($store->albums('lib-1'));
        $second = $this->await($store->albums('lib-2'));

        self::assertSame('2023-01-01', $first[0]->date);
        self::assertSame('2023-02-02', $second[0]->date);
        self::assertSame(2, $transport->requestCount(), 'a different library is a different cache key');
        self::assertStringContainsString('library_id=lib-2', $transport->requestAt(1)['url']);

        // The first library is still cached independently → no new request.
        $firstAgain = $this->await($store->albums('lib-1'));
        self::assertSame('2023-01-01', $firstAgain[0]->date);
        self::assertSame(2, $transport->requestCount(), 'each library caches independently');
    }

    public function testConcurrentAlbumFetchesAreDeduplicated(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->albums('lib-1');
        $second = $store->albums('lib-1');

        self::assertSame($first, $second, 'a concurrent fetch shares the in-flight promise');
        self::assertSame(1, $transport->requestCount(), 'only one underlying request');
    }

    public function testConcurrentAlbumFetchesForDifferentLibrariesAreSeparate(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->albums('lib-1');
        $second = $store->albums('lib-2');

        self::assertNotSame($first, $second, 'different libraries do not share an in-flight promise');
        self::assertSame(2, $transport->requestCount());
    }

    public function testFailedAlbumsFetchClearsInFlightSoARetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->albumsResponse());
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->albums('lib-1'));
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $albums = $this->await($store->albums('lib-1'));
        self::assertContainsOnlyInstancesOf(PhotoAlbum::class, $albums, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    // ---- photo() -------------------------------------------------------

    public function testPhotoFetchesAndCachesWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())->json(200, $this->photoResponse());
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $photo = $this->await($store->photo('p1'));
        self::assertInstanceOf(Photo::class, $photo);
        self::assertNotNull($photo->exif);
        self::assertSame('Canon', $photo->exif->cameraMake);
        self::assertSame('/api/v1/photo/photos/p1/thumbnail?sig=abc', $photo->thumbnailUrl);
        self::assertSame(1, $transport->requestCount());
        self::assertStringEndsWith('/api/v1/photo/photos/p1', $transport->requestAt(0)['url']);

        // Within TTL → cached, no second request.
        $now = 1030.0;
        $cached = $this->await($store->photo('p1'));
        self::assertSame('Canon', $cached->exif?->cameraMake);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testPhotoForceBypassesTheCache(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->photoResponse('Canon'))
            ->json(200, $this->photoResponse('Nikon'));
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->photo('p1'));
        $forced = $this->await($store->photo('p1', force: true));

        self::assertSame('Nikon', $forced->exif?->cameraMake);
        self::assertSame(2, $transport->requestCount(), 'force refetches even within TTL');
    }

    public function testConcurrentPhotoFetchesAreDeduplicated(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->photo('p1');
        $second = $store->photo('p1');

        self::assertSame($first, $second, 'a concurrent detail fetch shares the in-flight promise');
        self::assertSame(1, $transport->requestCount());
    }

    public function testFailedPhotoFetchClearsInFlightSoARetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->photoResponse());
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->photo('p1'));
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $photo = $this->await($store->photo('p1'));
        self::assertInstanceOf(Photo::class, $photo, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    // ---- invalidate() --------------------------------------------------

    public function testInvalidateForcesARefetchOfBothCaches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->albumsResponse('2023-01-01'))
            ->json(200, $this->photoResponse('Canon'))
            ->json(200, $this->albumsResponse('2023-02-02'))
            ->json(200, $this->photoResponse('Nikon'));
        $store = new PhotosStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        self::assertSame('2023-01-01', $this->await($store->albums('lib-1'))[0]->date);
        self::assertSame('Canon', $this->await($store->photo('p1'))->exif?->cameraMake);

        $store->invalidate();

        self::assertSame('2023-02-02', $this->await($store->albums('lib-1'))[0]->date);
        self::assertSame('Nikon', $this->await($store->photo('p1'))->exif?->cameraMake);
        self::assertSame(4, $transport->requestCount(), 'invalidate clears both the album and photo caches');
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

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Store\FavoritesStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Support\DeferredTransport;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

/**
 * A fetch that is in flight when a store is invalidated must not write the
 * cache when it settles: the write belongs to the generation that was dropped.
 */
final class StoreGenerationEpochTest extends TestCase
{
    /** @return array<string, mixed> */
    private function mediaPage(string $name): array
    {
        return ['items' => [['id' => 'm1', 'name' => $name, 'type' => 'movie']], 'total' => 1, 'offset' => 0, 'limit' => 18];
    }

    /** @return array<string, mixed> */
    private function favoritesPage(string $name): array
    {
        return ['items' => [['id' => 'm1', 'name' => $name, 'type' => 'movie']], 'total' => 1, 'limit' => 100, 'offset' => 0];
    }

    /** Record a promise's outcome on a plain object the closures mutate. */
    private function track(PromiseInterface $promise): \stdClass
    {
        $outcome = new \stdClass();
        $outcome->value = null;
        $outcome->error = null;
        $promise->then(
            static function (mixed $value) use ($outcome): void {
                $outcome->value = $value;
            },
            static function (\Throwable $error) use ($outcome): void {
                $outcome->error = $error;
            },
        );

        return $outcome;
    }

    private function fixedClock(): \Closure
    {
        return static fn (): float => 1000.0;
    }

    public function testMediaWriteStartedBeforeInvalidateIsDiscarded(): void
    {
        $transport = new DeferredTransport();
        $store = new MediaStore(new ApiClient('https://old.example', $transport), 60.0, $this->fixedClock());
        $query = MediaQuery::forLibrary('lib-1', limit: 18);

        $caller = $this->track($store->page($query));
        $store->invalidate();
        $transport->respondJson(0, $this->mediaPage('Stale'));

        self::assertInstanceOf(MediaPage::class, $caller->value, 'the caller still settles with its response');

        $this->track($store->page($query));
        self::assertSame(2, $transport->requestCount(), 'the stale write was not cached, so the next page() refetches');
    }

    public function testStaleMediaWriteDoesNotClearAFreshInFlightFetch(): void
    {
        $transport = new DeferredTransport();
        $store = new MediaStore(new ApiClient('https://old.example', $transport), 60.0, $this->fixedClock());
        $query = MediaQuery::forLibrary('lib-1', limit: 18);

        $this->track($store->page($query));
        $store->invalidate();
        $fresh = $this->track($store->page($query));

        $transport->respondJson(0, $this->mediaPage('Stale'));
        $coalesced = $this->track($store->page($query));
        self::assertSame(2, $transport->requestCount(), 'the fresh in-flight fetch still coalesces callers');

        $transport->respondJson(1, $this->mediaPage('Fresh'));
        self::assertSame('Fresh', $fresh->value->items[0]->name);
        self::assertSame('Fresh', $coalesced->value->items[0]->name);
        self::assertSame('Fresh', $this->track($store->page($query))->value->items[0]->name, 'the cache holds the fresh page');
        self::assertSame(2, $transport->requestCount());
    }

    public function testFavoritesWriteStartedBeforeInvalidateIsDiscarded(): void
    {
        $transport = new DeferredTransport();
        $store = new FavoritesStore(new ApiClient('https://old.example', $transport), 60.0, $this->fixedClock());

        $caller = $this->track($store->page());
        $store->invalidate();
        $transport->respondJson(0, $this->favoritesPage('Stale'));

        self::assertInstanceOf(MediaPage::class, $caller->value);

        $this->track($store->page());
        self::assertSame(2, $transport->requestCount(), 'the stale favorites write was not cached');
    }

    public function testStaleFavoritesWriteDoesNotClearAFreshInFlightFetch(): void
    {
        $transport = new DeferredTransport();
        $store = new FavoritesStore(new ApiClient('https://old.example', $transport), 60.0, $this->fixedClock());

        $this->track($store->page());
        $store->invalidate();
        $fresh = $this->track($store->page());

        $transport->respondJson(0, $this->favoritesPage('Stale'));
        $this->track($store->page());
        self::assertSame(2, $transport->requestCount(), 'the fresh in-flight favorites fetch still coalesces callers');

        $transport->respondJson(1, $this->favoritesPage('Fresh'));
        self::assertSame('Fresh', $fresh->value->items[0]->name);
        self::assertSame('Fresh', $this->track($store->page())->value->items[0]->name);
        self::assertSame(2, $transport->requestCount());
    }

    public function testLibrariesWriteStartedBeforeInvalidateIsDropped(): void
    {
        $transport = new DeferredTransport();
        $store = new LibrariesStore(new ApiClient('https://old.example', $transport), 60.0, $this->fixedClock());

        $this->track($store->all());
        $store->invalidate();
        // The next all() re-creates the cache slot, the window the stale write used to land in.
        $this->track($store->all());

        $transport->respondJson(0, ['libraries' => [['id' => 'old', 'name' => 'Stale', 'type' => 'movie']]]);
        self::assertNull($store->cached(), 'a stale libraries write does not reach the cache');

        $transport->respondJson(1, ['libraries' => [['id' => 'new', 'name' => 'Fresh', 'type' => 'movie']]]);
        self::assertNotNull($store->cached(), 'the fresh write is cached');
        self::assertSame('new', $store->cached()[0]->id);
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Chapter;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\LetterIndex;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\Dto\MediaRatings;
use Phlix\Console\Api\MediaQuery;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Caches media pages (keyed by query + paging) and the continue-watching list
 * over the {@see ApiClient} with a short TTL, and serves the library grid's
 * sparse, absolute-index range fetches via {@see ensureRange()}.
 */
final class MediaStore
{
    /** Maximum number of page entries to cache. */
    private const PAGE_CAPACITY = 2000;

    /** Maximum number of item/detail entries to cache. */
    private const ITEM_CAPACITY = 500;

    /** @phpstan-var LruMap<array{page: MediaPage, at: float}> */
    private LruMap $pages;

    /** @var array<string, PromiseInterface<MediaPage>>  page key → in-flight fetch */
    private array $inFlight = [];

    /** @phpstan-var LruMap<array{index: LetterIndex, at: float}> */
    private LruMap $letterIndexes;

    /** @phpstan-var LruMap<array{item: MediaItem, at: float}> */
    private LruMap $items;

    /** @var array<string, PromiseInterface<MediaItem>>  item id → in-flight detail fetch */
    private array $itemsInFlight = [];

    /** @phpstan-var LruMap<array{ratings: MediaRatings, at: float}> */
    private LruMap $ratings;

    /** @var array<string, PromiseInterface<MediaRatings>>  item id → in-flight ratings fetch */
    private array $ratingsInFlight = [];

    /** @phpstan-var LruMap<array{chapters: list<Chapter>, at: float}> */
    private LruMap $chapters;

    /** @var array<string, PromiseInterface<list<Chapter>>>  item id → in-flight chapters fetch */
    private array $chaptersInFlight = [];

    /** @var array{items: list<ContinueWatchingItem>, at: float}|null */
    private ?array $continue = null;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly float $ttl = 60.0,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->pages = new LruMap(self::PAGE_CAPACITY);
        $this->letterIndexes = new LruMap(self::PAGE_CAPACITY);
        $this->items = new LruMap(self::ITEM_CAPACITY);
        $this->ratings = new LruMap(self::ITEM_CAPACITY);
        $this->chapters = new LruMap(self::ITEM_CAPACITY);
    }

    public function api(): ApiClient
    {
        return $this->api;
    }

    /**
     * @return PromiseInterface<MediaPage>
     */
    public function page(MediaQuery $query, bool $force = false): PromiseInterface
    {
        $key = $this->pageKey($query);
        $now = ($this->clock)();

        // Use peek() to check existence and TTL without promoting an expired entry.
        if (!$force && ($entry = $this->pages->peek($key)) !== null && ($now - $entry['at']) < $this->ttl) {
            return resolve($this->pages->get($key)['page']);
        }

        // Coalesce concurrent fetches of the same page so a scroll that revisits
        // an in-flight offset never doubles up the request. Drive a Deferred so
        // the guard is registered before the inner request can settle (react may
        // resolve synchronously) and cleared exactly once.
        if (isset($this->inFlight[$key])) {
            return $this->inFlight[$key];
        }

        /** @var Deferred<MediaPage> $deferred */
        $deferred = new Deferred();
        $this->inFlight[$key] = $deferred->promise();

        $this->api->media($query)->then(
            function (MediaPage $page) use ($key, $now, $deferred): void {
                $this->pages->set($key, ['page' => $page, 'at' => $now]);
                unset($this->inFlight[$key]);
                $deferred->resolve($page);
            },
            function (\Throwable $error) use ($key, $deferred): void {
                unset($this->inFlight[$key]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * Fetch a single item's full detail — the shaped detail endpoint, which adds
     * the signed `stream_url` + `streams` the list shape omits — TTL-cached and
     * de-duplicated like {@see page()} so the detail screen can refetch freely.
     *
     * @return PromiseInterface<MediaItem>
     */
    public function item(string $id, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        if (!$force && ($entry = $this->items->peek($id)) !== null && ($now - $entry['at']) < $this->ttl) {
            return resolve($this->items->get($id)['item']);
        }

        if (isset($this->itemsInFlight[$id])) {
            return $this->itemsInFlight[$id];
        }

        /** @var Deferred<MediaItem> $deferred */
        $deferred = new Deferred();
        $this->itemsInFlight[$id] = $deferred->promise();

        $this->api->mediaItem($id)->then(
            function (MediaItem $item) use ($id, $now, $deferred): void {
                $this->items->set($id, ['item' => $item, 'at' => $now]);
                unset($this->itemsInFlight[$id]);
                $deferred->resolve($item);
            },
            function (\Throwable $error) use ($id, $deferred): void {
                unset($this->itemsInFlight[$id]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * Fetch all ratings for a media item (TMDB, IMDb, user, aggregated).
     * TTL-cached and de-duplicated like {@see item()}.
     *
     * @return PromiseInterface<MediaRatings>
     */
    public function ratings(string $id, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        if (!$force && ($entry = $this->ratings->peek($id)) !== null && ($now - $entry['at']) < $this->ttl) {
            return resolve($this->ratings->get($id)['ratings']);
        }

        if (isset($this->ratingsInFlight[$id])) {
            return $this->ratingsInFlight[$id];
        }

        /** @var Deferred<MediaRatings> $deferred */
        $deferred = new Deferred();
        $this->ratingsInFlight[$id] = $deferred->promise();

        $this->api->mediaRatings($id)->then(
            function (MediaRatings $mediaRatings) use ($id, $now, $deferred): void {
                $this->ratings->set($id, ['ratings' => $mediaRatings, 'at' => $now]);
                unset($this->ratingsInFlight[$id]);
                $deferred->resolve($mediaRatings);
            },
            function (\Throwable $error) use ($id, $deferred): void {
                unset($this->ratingsInFlight[$id]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * Fetch the chapter list for a media item (movie/episode).
     * TTL-cached and de-duplicated like {@see item()}.
     *
     * @return PromiseInterface<list<Chapter>>
     */
    public function chapters(string $id, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        if (!$force && ($entry = $this->chapters->peek($id)) !== null && ($now - $entry['at']) < $this->ttl) {
            return resolve($this->chapters->get($id)['chapters']);
        }

        if (isset($this->chaptersInFlight[$id])) {
            return $this->chaptersInFlight[$id];
        }

        /** @var Deferred<list<Chapter>> $deferred */
        $deferred = new Deferred();
        $this->chaptersInFlight[$id] = $deferred->promise();

        $this->api->mediaChapters($id)->then(
            function (array $chapters) use ($id, $now, $deferred): void {
                $this->chapters->set($id, ['chapters' => $chapters, 'at' => $now]);
                unset($this->chaptersInFlight[$id]);
                $deferred->resolve($chapters);
            },
            function (\Throwable $error) use ($id, $deferred): void {
                unset($this->chaptersInFlight[$id]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * Fetch the page(s) covering the absolute-index window [$start, $end] and
     * resolve the items keyed by their ABSOLUTE index, plus the total. Pages are
     * TTL-cached and de-duplicated, so the grid can call this freely on scroll.
     *
     * @param MediaQuery $query  The query defining filters and page size ($limit)
     * @param int        $start  Absolute start index (inclusive)
     * @param int        $end    Absolute end index (inclusive)
     *
     * @return PromiseInterface<MediaRange>
     *
     * @note The $windowEnd calculation uses max($start, $end - 1) to ensure the
     *       last page is included in the fetch even when $end falls exactly on
     *       a page boundary. Without this, lastOffset would under-fetch and miss
     *       items at the boundary of the final page.
     */
    public function ensureRange(MediaQuery $query, int $start, int $end): PromiseInterface
    {
        if ($end < 0 || $start > $end) {
            return resolve(new MediaRange([], 0));
        }

        $start = max(0, $start);
        $limit = max(1, $query->limit);
        // Use windowEnd to ensure we fetch all pages needed to cover items from $start to $end.
        // $end is inclusive, so windowEnd = $end - 1. Using max() handles edge case where $end < $start.
        $windowEnd = max($start, $end - 1);
        $firstOffset = intdiv($start, $limit) * $limit;
        $lastOffset = intdiv($windowEnd, $limit) * $limit;

        /** @var array<int, PromiseInterface<MediaPage>> $promises */
        $promises = [];
        for ($offset = $firstOffset; $offset <= $lastOffset; $offset += $limit) {
            $promises[$offset] = $this->page($query->withOffset($offset));
        }

        return all($promises)->then(static function (array $pages) use ($start, $end): MediaRange {
            $items = [];
            $total = 0;
            foreach ($pages as $offset => $page) {
                $total = max($total, $page->total);
                foreach ($page->items as $i => $item) {
                    $absolute = $offset + $i;
                    if ($absolute >= $start && $absolute <= $end) {
                        $items[$absolute] = $item;
                    }
                }
            }
            ksort($items);

            return new MediaRange($items, $total);
        });
    }

    /**
     * The A–Z jump index for a query's filters (paging-independent), TTL-cached.
     *
     * @return PromiseInterface<LetterIndex>
     */
    public function letterIndex(MediaQuery $query, bool $force = false): PromiseInterface
    {
        $key = $query->cacheKey();
        $now = ($this->clock)();

        if (!$force && ($entry = $this->letterIndexes->peek($key)) !== null && ($now - $entry['at']) < $this->ttl) {
            return resolve($this->letterIndexes->get($key)['index']);
        }

        return $this->api->letterIndex($query)->then(function (LetterIndex $index) use ($key, $now): LetterIndex {
            $this->letterIndexes->set($key, ['index' => $index, 'at' => $now]);

            return $index;
        });
    }

    /**
     * @return PromiseInterface<list<ContinueWatchingItem>>
     */
    public function continueWatching(bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        if (!$force && $this->continue !== null && ($now - $this->continue['at']) < $this->ttl) {
            return resolve($this->continue['items']);
        }

        return $this->api->continueWatching()->then(function (array $items) use ($now): array {
            $this->continue = ['items' => $items, 'at' => $now];

            return $items;
        });
    }

    public function invalidate(): void
    {
        $this->pages->clear();
        $this->inFlight = [];
        $this->letterIndexes->clear();
        $this->items->clear();
        $this->itemsInFlight = [];
        $this->ratings->clear();
        $this->ratingsInFlight = [];
        $this->chapters->clear();
        $this->chaptersInFlight = [];
        $this->continue = null;
    }

    private function pageKey(MediaQuery $query): string
    {
        return $query->cacheKey() . ':' . $query->offset . ':' . $query->limit;
    }
}

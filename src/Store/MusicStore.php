<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Album;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Caches music album pages over the {@see ApiClient} with a short TTL,
 * de-duplicating concurrent fetches. Pages are keyed by offset so scrolling
 * to a previously-visited region never re-fetches. The screen uses
 * {@see ensureRange()} to fetch only the visible window (plus overscan), so
 * even a 5,000-album library scrolls smoothly.
 */
final class MusicStore
{
    /** @var LruMap */
    private LruMap $pages;

    /** @var array<string, PromiseInterface<\Phlix\Console\Api\Dto\AlbumPage>>  In-flight page fetches, keyed by offset. */
    private array $inFlight = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private const DEFAULT_LIMIT = 100;

    /**
     * @param (\Closure(): float)|null $clock
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly float $ttl = 60.0,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->pages = new LruMap(64); // enough for several pages of scrolling
    }

    /**
     * Fetch the page(s) covering the absolute-index window [$start, $end] and
     * resolve the albums keyed by their ABSOLUTE index, plus the total. Pages are
     * cached and de-duplicated so a scroll that revisits an in-flight offset never
     * doubles the request.
     *
     * @return PromiseInterface<MusicRange>
     */
    public function ensureRange(int $start, int $end, int $limit = self::DEFAULT_LIMIT): PromiseInterface
    {
        if ($end < 0 || $start > $end) {
            return resolve(new MusicRange([], 0));
        }

        $start = max(0, $start);
        $windowEnd = max($start, $end - 1);
        $firstOffset = intdiv($start, $limit) * $limit;
        $lastOffset = intdiv($windowEnd, $limit) * $limit;

        /** @var array<int, PromiseInterface<\Phlix\Console\Api\Dto\AlbumPage>> $promises */
        $promises = [];
        for ($offset = $firstOffset; $offset <= $lastOffset; $offset += $limit) {
            $promises[$offset] = $this->page($offset, $limit);
        }

        return all($promises)->then(static function (array $pages) use ($start, $end): MusicRange {
            $albums = [];
            $total = 0;
            foreach ($pages as $offset => $page) {
                $total = max($total, $page->total);
                foreach ($page->albums as $i => $album) {
                    $absolute = $offset + $i;
                    if ($absolute >= $start && $absolute <= $end) {
                        $albums[$absolute] = $album;
                    }
                }
            }
            ksort($albums);

            return new MusicRange($albums, $total);
        });
    }

    /**
     * @return PromiseInterface<\Phlix\Console\Api\Dto\AlbumPage>
     */
    private function page(int $offset, int $limit): PromiseInterface
    {
        $key = "{$offset}:{$limit}";
        $now = ($this->clock)();

        $entry = $this->pages->peek($key);
        /** @var array{page: \Phlix\Console\Api\Dto\AlbumPage, at: float}|null $entry */
        if ($entry !== null && ($now - $entry['at']) < $this->ttl) {
            $cached = $this->pages->get($key);
            /** @var array{page: \Phlix\Console\Api\Dto\AlbumPage, at: float} $cached */
            return resolve($cached['page']);
        }

        if (isset($this->inFlight[$key])) {
            return $this->inFlight[$key];
        }

        /** @var Deferred<\Phlix\Console\Api\Dto\AlbumPage> $deferred */
        $deferred = new Deferred();
        $this->inFlight[$key] = $deferred->promise();

        $this->api->musicAlbums($limit, $offset)->then(
            function (\Phlix\Console\Api\Dto\AlbumPage $page) use ($key, $now, $deferred): void {
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

    public function invalidate(): void
    {
        $this->pages->clear();
        $this->inFlight = [];
    }
}

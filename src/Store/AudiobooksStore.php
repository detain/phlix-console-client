<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Api\Dto\AudiobookPage;
use Phlix\Console\Api\Dto\AudiobookProgress;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Caches audiobook pages (keyed by library + paging) and single-audiobook
 * details and chapter lists over the {@see ApiClient} with a short TTL,
 * de-duplicating concurrent fetches. Mirrors {@see BooksStore}.
 *
 * The screen uses {@see ensureRange()} to fetch only the visible window (plus
 * overscan), so even a large library scrolls smoothly. Progress is never
 * cached (it must always be read fresh).
 */
final class AudiobooksStore
{
    /** The server caps a page at 100; a full page means there may be more. */
    private const PAGE_SIZE = 100;

    /** A hard safety ceiling on the page loop (100 * 50 = 5000 audiobooks). */
    private const MAX_PAGES = 50;

    /** Maximum number of page entries to cache. */
    private const PAGE_CAPACITY = 2000;

    /** Maximum number of item/detail entries to cache. */
    private const ITEM_CAPACITY = 500;

    /** @var LruMap */
    private LruMap $pages;

    /** @var array<string, PromiseInterface<AudiobookPage>>  page key → in-flight fetch */
    private array $inFlight = [];

    /** @var LruMap */
    private LruMap $allLists;

    /** @var array<string, PromiseInterface<list<Audiobook>>>  library key → in-flight all() fetch */
    private array $allInFlight = [];

    /** @var LruMap */
    private LruMap $audiobooks;

    /** @var array<string, PromiseInterface<Audiobook>>  id → in-flight detail fetch */
    private array $audiobooksInFlight = [];

    /** @var LruMap */
    private LruMap $chapters;

    /** @var array<string, PromiseInterface<list<AudiobookChapter>>>  id → in-flight chapters fetch */
    private array $chaptersInFlight = [];

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
        $this->allLists = new LruMap(self::PAGE_CAPACITY);
        $this->audiobooks = new LruMap(self::ITEM_CAPACITY);
        $this->chapters = new LruMap(self::ITEM_CAPACITY);
    }

    /**
     * A page of audiobooks for a library (or all libraries when `$libraryId`
     * is null), TTL-cached and de-duplicated so the screen can call it freely
     * on scroll.
     *
     * @return PromiseInterface<AudiobookPage>
     */
    public function page(?string $libraryId, int $limit, int $offset, bool $force = false): PromiseInterface
    {
        $key = ($libraryId ?? '') . '|' . $limit . '|' . $offset;
        $now = ($this->clock)();

        $entry = $this->pages->peek($key);
        /** @var array{page: AudiobookPage, at: float}|null $entry */
        if (!$force && $entry !== null && ($now - $entry['at']) < $this->ttl) {
            $cached = $this->pages->get($key);
            /** @var array{page: AudiobookPage, at: float} $cached */
            return resolve($cached['page']);
        }

        // Coalesce concurrent fetches of the same page. Drive a Deferred so the
        // guard is registered before the inner request can settle (react may
        // resolve synchronously) and cleared exactly once on settle/reject.
        if (isset($this->inFlight[$key])) {
            return $this->inFlight[$key];
        }

        /** @var Deferred<AudiobookPage> $deferred */
        $deferred = new Deferred();
        $this->inFlight[$key] = $deferred->promise();

        $this->api->audiobooks($libraryId, $limit, $offset)->then(
            function (AudiobookPage $page) use ($key, $now, $deferred): void {
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
     * Fetch the page(s) covering the absolute-index window [$start, $end] and
     * resolve the audiobooks keyed by their ABSOLUTE index. Unlike
     * {@see MusicStore::ensureRange()} the `/audiobooks` endpoint sends no
     * total, so the library's total ($total — the item count) is PASSED IN and
     * used only to clamp the window; the returned map carries just the
     * audiobooks. Pages are TTL-cached and de-duplicated via {@see page()},
     * so the screen can call this freely on scroll.
     *
     * @param string|null $libraryId  The library to fetch from, or null for all
     * @param int         $total      Total number of audiobooks in the library (used to clamp $end)
     * @param int         $start      Absolute start index (inclusive)
     * @param int         $end        Absolute end index (inclusive)
     * @param int         $limit      Number of items per page (default 100, matching server cap)
     *
     * @return PromiseInterface<AudiobookRange>
     */
    public function ensureRange(?string $libraryId, int $total, int $start, int $end, int $limit = 100): PromiseInterface
    {
        $limit = max(1, $limit);
        $start = max(0, $start);
        // Clamp the window to the known total so an overscan past the last
        // audiobook never requests pages that cannot exist.
        if ($total > 0) {
            $end = min($end, $total - 1);
        }
        if ($end < 0 || $start > $end) {
            return resolve(new AudiobookRange([]));
        }

        $windowEnd = max($start, $end - 1);
        $firstOffset = intdiv($start, $limit) * $limit;
        $lastOffset = intdiv($windowEnd, $limit) * $limit;

        /** @var array<int, PromiseInterface<AudiobookPage>> $promises */
        $promises = [];
        for ($offset = $firstOffset; $offset <= $lastOffset; $offset += $limit) {
            $promises[$offset] = $this->page($libraryId, $limit, $offset);
        }

        return all($promises)->then(static function (array $pages) use ($start, $end): AudiobookRange {
            $audiobooks = [];
            foreach ($pages as $offset => $page) {
                foreach ($page->audiobooks as $i => $audiobook) {
                    $absolute = $offset + $i;
                    if ($absolute >= $start && $absolute <= $end) {
                        $audiobooks[$absolute] = $audiobook;
                    }
                }
            }
            ksort($audiobooks);

            return new AudiobookRange($audiobooks);
        });
    }

    /**
     * The full audiobook list for a library (or all libraries when `$libraryId`
     * is null), TTL-cached and de-duplicated. On a miss it pages through the
     * server's 100-capped endpoint, accumulating every audiobook.
     *
     * @return PromiseInterface<list<Audiobook>>
     */
    public function all(?string $libraryId, bool $force = false): PromiseInterface
    {
        $key = $libraryId ?? '';
        $now = ($this->clock)();

        $entry = $this->allLists->peek($key);
        /** @var array{list: list<Audiobook>, at: float}|null $entry */
        if (!$force && $entry !== null && ($now - $entry['at']) < $this->ttl) {
            $cached = $this->allLists->get($key);
            /** @var array{list: list<Audiobook>, at: float} $cached */
            return resolve($cached['list']);
        }

        // Coalesce concurrent fetches of the same library. Drive a Deferred so
        // the guard is registered before the inner request chain can settle
        // (react may resolve synchronously) and cleared exactly once.
        if (isset($this->allInFlight[$key])) {
            return $this->allInFlight[$key];
        }

        /** @var Deferred<list<Audiobook>> $deferred */
        $deferred = new Deferred();
        $this->allInFlight[$key] = $deferred->promise();

        $this->fetchAllPages($libraryId, 0, 0, [])->then(
            function (array $list) use ($key, $now, $deferred): void {
                $this->allLists->set($key, ['list' => $list, 'at' => $now]);
                unset($this->allInFlight[$key]);
                $deferred->resolve($list);
            },
            function (\Throwable $error) use ($key, $deferred): void {
                unset($this->allInFlight[$key]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * Fetch one page at `$offset`, append it to `$accumulated`, then either
     * resolve the full list (a short or empty page, or the safety cap) or chain
     * the next page. A recursive promise chain so the whole loop rides the one
     * Deferred {@see all()} registered for dedup.
     *
     * @param list<Audiobook> $accumulated
     *
     * @return PromiseInterface<list<Audiobook>>
     */
    private function fetchAllPages(?string $libraryId, int $offset, int $pageCount, array $accumulated): PromiseInterface
    {
        return $this->api->audiobooks($libraryId, self::PAGE_SIZE, $offset)->then(
            function (AudiobookPage $page) use ($libraryId, $offset, $pageCount, $accumulated): PromiseInterface {
                foreach ($page->audiobooks as $audiobook) {
                    $accumulated[] = $audiobook;
                }

                // A short (or empty) page means the library is exhausted; the
                // safety cap stops a misbehaving server from looping forever.
                if (count($page->audiobooks) < self::PAGE_SIZE || ($pageCount + 1) >= self::MAX_PAGES) {
                    return resolve($accumulated);
                }

                return $this->fetchAllPages($libraryId, $offset + self::PAGE_SIZE, $pageCount + 1, $accumulated);
            },
        );
    }

    /**
     * A single audiobook's detail — the shape that adds the signed stream URL
     * the list omits — TTL-cached and de-duplicated like {@see all()}.
     *
     * @return PromiseInterface<Audiobook>
     */
    public function audiobook(string $id, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        $entry = $this->audiobooks->peek($id);
        /** @var array{audiobook: Audiobook, at: float}|null $entry */
        if (!$force && $entry !== null && ($now - $entry['at']) < $this->ttl) {
            $cached = $this->audiobooks->get($id);
            /** @var array{audiobook: Audiobook, at: float} $cached */
            return resolve($cached['audiobook']);
        }

        if (isset($this->audiobooksInFlight[$id])) {
            return $this->audiobooksInFlight[$id];
        }

        /** @var Deferred<Audiobook> $deferred */
        $deferred = new Deferred();
        $this->audiobooksInFlight[$id] = $deferred->promise();

        $this->api->audiobook($id)->then(
            function (Audiobook $audiobook) use ($id, $now, $deferred): void {
                $this->audiobooks->set($id, ['audiobook' => $audiobook, 'at' => $now]);
                unset($this->audiobooksInFlight[$id]);
                $deferred->resolve($audiobook);
            },
            function (\Throwable $error) use ($id, $deferred): void {
                unset($this->audiobooksInFlight[$id]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * The formatted chapter list for an audiobook, TTL-cached and de-duplicated.
     *
     * @return PromiseInterface<list<AudiobookChapter>>
     */
    public function chapters(string $id, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        $entry = $this->chapters->peek($id);
        /** @var array{chapters: list<AudiobookChapter>, at: float}|null $entry */
        if (!$force && $entry !== null && ($now - $entry['at']) < $this->ttl) {
            $cached = $this->chapters->get($id);
            /** @var array{chapters: list<AudiobookChapter>, at: float} $cached */
            return resolve($cached['chapters']);
        }

        if (isset($this->chaptersInFlight[$id])) {
            return $this->chaptersInFlight[$id];
        }

        /** @var Deferred<list<AudiobookChapter>> $deferred */
        $deferred = new Deferred();
        $this->chaptersInFlight[$id] = $deferred->promise();

        $this->api->audiobookChapters($id)->then(
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
     * The listener's progress through an audiobook — NEVER cached (it must
     * always reflect the latest position), a thin delegate to the client.
     *
     * @return PromiseInterface<AudiobookProgress>
     */
    public function progress(string $id): PromiseInterface
    {
        return $this->api->audiobookProgress($id);
    }

    /**
     * Persist the listener's progress — a thin delegate to the client.
     *
     * @param list<int> $completedChapters
     *
     * @return PromiseInterface<AudiobookProgress>
     */
    public function saveProgress(
        string $id,
        int $positionMs,
        int $currentChapterIndex,
        array $completedChapters = [],
        float $percentComplete = 0.0,
    ): PromiseInterface {
        return $this->api->saveAudiobookProgress($id, $positionMs, $currentChapterIndex, $completedChapters, $percentComplete);
    }

    public function invalidate(): void
    {
        $this->pages->clear();
        $this->inFlight = [];
        $this->allLists->clear();
        $this->allInFlight = [];
        $this->audiobooks->clear();
        $this->audiobooksInFlight = [];
        $this->chapters->clear();
        $this->chaptersInFlight = [];
    }
}

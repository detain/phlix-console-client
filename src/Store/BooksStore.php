<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Book;
use Phlix\Console\Api\Dto\BookPage;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Caches book pages (keyed by library + paging) and single-book details over the
 * {@see ApiClient} with a short TTL, de-duplicating concurrent fetches. Mirrors
 * {@see MediaStore}; the book detail cache exists so the grid can lazily resolve
 * a card's signed cover URL on demand.
 */
final class BooksStore
{
    /** Maximum number of page entries to cache. */
    private const PAGE_CAPACITY = 2000;

    /** Maximum number of item/detail entries to cache. */
    private const ITEM_CAPACITY = 500;

    /** @phpstan-var LruMap<string, array{page: BookPage, at: float}>  page key → cached page */
    private LruMap $pages;

    /** @var array<string, PromiseInterface<BookPage>>  page key → in-flight fetch */
    private array $inFlight = [];

    /** @phpstan-var LruMap<string, array{book: Book, at: float}>  book id → cached detail */
    private LruMap $books;

    /** @var array<string, PromiseInterface<Book>>  book id → in-flight detail fetch */
    private array $booksInFlight = [];

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
        $this->books = new LruMap(self::ITEM_CAPACITY);
    }

    /**
     * A page of books for a library (or all libraries when `$libraryId` is null),
     * TTL-cached and de-duplicated so the grid can call it freely on scroll.
     *
     * @return PromiseInterface<BookPage>
     */
    public function page(?string $libraryId, int $limit, int $offset, bool $force = false): PromiseInterface
    {
        $key = ($libraryId ?? '') . '|' . $limit . '|' . $offset;
        $now = ($this->clock)();

        if (!$force && ($entry = $this->pages->peek($key)) !== null && ($now - $entry['at']) < $this->ttl) {
            return resolve($this->pages->get($key)['page']);
        }

        // Coalesce concurrent fetches of the same page. Drive a Deferred so the
        // guard is registered before the inner request can settle (react may
        // resolve synchronously) and cleared exactly once on settle/reject.
        if (isset($this->inFlight[$key])) {
            return $this->inFlight[$key];
        }

        /** @var Deferred<BookPage> $deferred */
        $deferred = new Deferred();
        $this->inFlight[$key] = $deferred->promise();

        $this->api->books($libraryId, $limit, $offset)->then(
            function (BookPage $page) use ($key, $now, $deferred): void {
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
     * A single book's detail — the shape that adds the signed cover/read/download
     * URLs the list omits — TTL-cached and de-duplicated like {@see page()}.
     *
     * @return PromiseInterface<Book>
     */
    public function book(string $id, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        if (!$force && ($entry = $this->books->peek($id)) !== null && ($now - $entry['at']) < $this->ttl) {
            return resolve($this->books->get($id)['book']);
        }

        if (isset($this->booksInFlight[$id])) {
            return $this->booksInFlight[$id];
        }

        /** @var Deferred<Book> $deferred */
        $deferred = new Deferred();
        $this->booksInFlight[$id] = $deferred->promise();

        $this->api->book($id)->then(
            function (Book $book) use ($id, $now, $deferred): void {
                $this->books->set($id, ['book' => $book, 'at' => $now]);
                unset($this->booksInFlight[$id]);
                $deferred->resolve($book);
            },
            function (\Throwable $error) use ($id, $deferred): void {
                unset($this->booksInFlight[$id]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * Fetch the page(s) covering the absolute-index window [$start, $end] and
     * resolve the books keyed by their ABSOLUTE index. Unlike
     * {@see MediaStore::ensureRange()} the `/books` endpoint sends no total, so
     * the grid's total ($total — the library's item count) is PASSED IN and used
     * only to clamp the window; the returned map carries just the books. Pages
     * are TTL-cached and de-duplicated via {@see page()}, so the grid can call
     * this freely on scroll.
     *
     * @param string|null $libraryId  The library to fetch from, or null for all
     * @param int         $total      Total number of books in the library (used to clamp $end)
     * @param int         $start      Absolute start index (inclusive)
     * @param int         $end        Absolute end index (inclusive)
     * @param int         $limit      Number of items per page (default 50)
     *
     * @return PromiseInterface<array<int, Book>>
     *
     * @note The $windowEnd calculation uses max($start, $end - 1) to ensure the
     *       last page is included in the fetch even when $end falls exactly on
     *       a page boundary. Without this, lastOffset would under-fetch and miss
     *       items at the boundary of the final page.
     */
    public function ensureRange(?string $libraryId, int $total, int $start, int $end, int $limit = 50): PromiseInterface
    {
        $limit = max(1, $limit);
        $start = max(0, $start);
        // Clamp the window to the known total so a grid overscan past the last
        // book never requests pages that cannot exist.
        if ($total > 0) {
            $end = min($end, $total - 1);
        }
        if ($end < 0 || $start > $end) {
            return resolve([]);
        }

        $windowEnd = max($start, $end - 1);
        $firstOffset = intdiv($start, $limit) * $limit;
        $lastOffset = intdiv($windowEnd, $limit) * $limit;

        /** @var array<int, PromiseInterface<BookPage>> $promises */
        $promises = [];
        for ($offset = $firstOffset; $offset <= $lastOffset; $offset += $limit) {
            $promises[$offset] = $this->page($libraryId, $limit, $offset);
        }

        return all($promises)->then(static function (array $pages) use ($start, $end): array {
            $books = [];
            foreach ($pages as $offset => $page) {
                foreach ($page->books as $i => $book) {
                    $absolute = $offset + $i;
                    if ($absolute >= $start && $absolute <= $end) {
                        $books[$absolute] = $book;
                    }
                }
            }
            ksort($books);

            return $books;
        });
    }

    public function invalidate(): void
    {
        $this->pages->clear();
        $this->inFlight = [];
        $this->books->clear();
        $this->booksInFlight = [];
    }
}

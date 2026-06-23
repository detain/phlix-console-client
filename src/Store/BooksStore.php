<?php

declare(strict_types=1);

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
    /** @var array<string, array{page: BookPage, at: float}>  page key → cached page */
    private array $pages = [];

    /** @var array<string, PromiseInterface<BookPage>>  page key → in-flight fetch */
    private array $inFlight = [];

    /** @var array<string, array{book: Book, at: float}>  book id → cached detail */
    private array $books = [];

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

        if (!$force && isset($this->pages[$key]) && ($now - $this->pages[$key]['at']) < $this->ttl) {
            return resolve($this->pages[$key]['page']);
        }

        // Coalesce concurrent fetches of the same page. Drive a Deferred so the
        // guard is registered before the inner request can settle (react may
        // resolve synchronously) and cleared exactly once on settle/reject.
        if (isset($this->inFlight[$key])) {
            return $this->inFlight[$key];
        }

        $deferred = new Deferred();
        $this->inFlight[$key] = $deferred->promise();

        $this->api->books($libraryId, $limit, $offset)->then(
            function (BookPage $page) use ($key, $now, $deferred): void {
                $this->pages[$key] = ['page' => $page, 'at' => $now];
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

        if (!$force && isset($this->books[$id]) && ($now - $this->books[$id]['at']) < $this->ttl) {
            return resolve($this->books[$id]['book']);
        }

        if (isset($this->booksInFlight[$id])) {
            return $this->booksInFlight[$id];
        }

        $deferred = new Deferred();
        $this->booksInFlight[$id] = $deferred->promise();

        $this->api->book($id)->then(
            function (Book $book) use ($id, $now, $deferred): void {
                $this->books[$id] = ['book' => $book, 'at' => $now];
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
     * @return PromiseInterface<array<int, Book>>
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

        $firstOffset = intdiv($start, $limit) * $limit;
        $lastOffset = intdiv($end, $limit) * $limit;

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
        $this->pages = [];
        $this->inFlight = [];
        $this->books = [];
        $this->booksInFlight = [];
    }
}

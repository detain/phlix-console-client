<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Book;
use Phlix\Console\Api\Dto\BookPage;
use Phlix\Console\Store\BooksStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class BooksStoreTest extends TestCase
{
    /** A `/books` envelope: `{books:[…], limit, offset}`. */
    private function booksResponse(string $title = 'Dune', int $offset = 0): array
    {
        return [
            'books' => [
                ['id' => 'b1', 'name' => 'x.epub', 'path' => '/x/x.epub', 'metadata' => ['title' => $title, 'author' => 'Frank Herbert']],
            ],
            'limit' => 24,
            'offset' => $offset,
        ];
    }

    /**
     * A `/books` page whose book ids/titles equal their absolute index, so a
     * range fetch can be asserted positionally.
     */
    private function booksPage(int $offset, int $count, int $limit = 50): array
    {
        $books = [];
        for ($i = 0; $i < $count; $i++) {
            $abs = $offset + $i;
            $books[] = ['id' => (string) $abs, 'name' => "b{$abs}.epub", 'path' => "/x/b{$abs}.epub", 'metadata' => ['title' => "Book {$abs}"]];
        }

        return ['books' => $books, 'limit' => $limit, 'offset' => $offset];
    }

    /** A `/books/{id}` envelope: `{book: {…signed}}`. */
    private function bookResponse(string $title = 'Dune'): array
    {
        return ['book' => [
            'id' => 'b1',
            'name' => 'x.epub',
            'type' => 'book',
            'path' => '/x/x.epub',
            'metadata' => ['title' => $title, 'author' => 'Frank Herbert'],
            'cover_url' => '/api/v1/books/b1/cover?sig=abc',
            'download_url' => '/api/v1/books/b1/download?sig=ghi',
            'read_url' => '/api/v1/books/b1/read?sig=def',
        ]];
    }

    // ---- page() --------------------------------------------------------

    public function testPageFetchesAndCachesWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        // Only ONE queued response: a second uncached call would exhaust the
        // queue (and fall back to an empty `{}` → 0 books), so the cache hit is
        // proven by the books still being present and no new request.
        $transport = (new FakeTransport())->json(200, $this->booksResponse());
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $page = $this->await($store->page('lib-1', 24, 0));
        self::assertInstanceOf(BookPage::class, $page);
        self::assertCount(1, $page->books);
        self::assertSame('Dune', $page->books[0]->title);
        self::assertSame(1, $transport->requestCount());
        self::assertStringContainsString('/api/v1/books?', $transport->requestAt(0)['url']);
        self::assertStringContainsString('library_id=lib-1', $transport->requestAt(0)['url']);

        // Within TTL → cached, no second request.
        $now = 1030.0;
        $cached = $this->await($store->page('lib-1', 24, 0));
        self::assertCount(1, $cached->books);
        self::assertSame('Dune', $cached->books[0]->title);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testPageRefetchesAfterTtlExpiry(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())
            ->json(200, $this->booksResponse('First'))
            ->json(200, $this->booksResponse('Second'));
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->page('lib-1', 24, 0));
        self::assertSame('First', $first->books[0]->title);
        self::assertSame(1, $transport->requestCount());

        // Past the TTL → refetch.
        $now = 1070.0;
        $second = $this->await($store->page('lib-1', 24, 0));
        self::assertSame('Second', $second->books[0]->title);
        self::assertSame(2, $transport->requestCount(), 'refetched after TTL expiry');
    }

    public function testPageForceBypassesTheCache(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->booksResponse('First'))
            ->json(200, $this->booksResponse('Second'));
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->page('lib-1', 24, 0));
        $forced = $this->await($store->page('lib-1', 24, 0, force: true));

        self::assertSame('Second', $forced->books[0]->title);
        self::assertSame(2, $transport->requestCount(), 'force refetches even within TTL');
    }

    public function testPagesAreKeyedByLibraryLimitAndOffset(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->booksResponse('First', 0))
            ->json(200, $this->booksResponse('Second', 24));
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $this->await($store->page('lib-1', 24, 0));
        $second = $this->await($store->page('lib-1', 24, 24));

        self::assertSame('First', $first->books[0]->title);
        self::assertSame('Second', $second->books[0]->title);
        self::assertSame(2, $transport->requestCount(), 'a different offset is a different cache key');
        self::assertStringContainsString('offset=24', $transport->requestAt(1)['url']);
    }

    public function testConcurrentPageFetchesAreDeduplicated(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->page('lib-1', 24, 0);
        $second = $store->page('lib-1', 24, 0);

        self::assertSame($first, $second, 'a concurrent fetch shares the in-flight promise');
        self::assertSame(1, $transport->requestCount(), 'only one underlying request');
    }

    public function testFailedPageFetchClearsInFlightSoARetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->booksResponse());
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->page('lib-1', 24, 0));
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $page = $this->await($store->page('lib-1', 24, 0));
        self::assertInstanceOf(BookPage::class, $page, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    // ---- ensureRange() -------------------------------------------------

    public function testEnsureRangeFetchesTheCoveringPageAndKeysByAbsoluteIndex(): void
    {
        // A window inside the first page (limit 50): one request, books keyed by
        // their absolute index, clipped to the requested window.
        $transport = (new FakeTransport())->json(200, $this->booksPage(0, 50, 50));
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $books = $this->await($store->ensureRange('lib-1', 200, 2, 5, 50));

        self::assertSame([2, 3, 4, 5], array_keys($books));
        self::assertSame('Book 2', $books[2]->title);
        self::assertSame('Book 5', $books[5]->title);
        self::assertSame(1, $transport->requestCount(), 'one page covers the window');
        self::assertStringContainsString('offset=0', $transport->requestAt(0)['url']);
    }

    public function testEnsureRangeFetchesEveryCoveringPageForAWindowSpanningPages(): void
    {
        // A window straddling the page boundary (limit 50): pages at offset 0 and
        // 50 are both fetched, and books are keyed by absolute index across them.
        $transport = (new FakeTransport())
            ->json(200, $this->booksPage(0, 50, 50))
            ->json(200, $this->booksPage(50, 50, 50));
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $books = $this->await($store->ensureRange('lib-1', 200, 48, 52, 50));

        self::assertSame([48, 49, 50, 51, 52], array_keys($books));
        self::assertSame('Book 48', $books[48]->title);
        self::assertSame('Book 52', $books[52]->title);
        self::assertSame(2, $transport->requestCount(), 'a window spanning two pages fetches both');
        self::assertStringContainsString('offset=0', $transport->requestAt(0)['url']);
        self::assertStringContainsString('offset=50', $transport->requestAt(1)['url']);
    }

    public function testEnsureRangeClampsTheWindowToThePassedTotal(): void
    {
        // total=5 (the library's item count); a grid overscan asks for [0, 30],
        // but the window is clamped so only the single covering page is fetched
        // and only the books that actually exist come back.
        $transport = (new FakeTransport())->json(200, $this->booksPage(0, 5, 50));
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $books = $this->await($store->ensureRange('lib-1', 5, 0, 30, 50));

        self::assertSame([0, 1, 2, 3, 4], array_keys($books), 'clamped to the total — no phantom indices');
        self::assertSame(1, $transport->requestCount(), 'the clamp keeps the fetch to the one real page');
    }

    public function testEnsureRangeWithAPartialLastPageOmitsTrailingIndices(): void
    {
        // The last page is partial (10 books, offset 50): a window into that tail
        // returns only the books present; the gap up to the total is simply
        // absent (the grid shows skeletons there). The window [55, 70] covers
        // only the offset-50 page, so that single partial page is the one served.
        $transport = (new FakeTransport())->json(200, $this->booksPage(50, 10, 50));
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $books = $this->await($store->ensureRange('lib-1', 80, 55, 70, 50));

        // Only 55..59 exist on the partial page; 60..70 are absent.
        self::assertSame([55, 56, 57, 58, 59], array_keys($books));
        self::assertArrayNotHasKey(60, $books, 'the partial last page leaves trailing indices absent');
        self::assertSame(1, $transport->requestCount(), 'only the covering page is fetched');
    }

    public function testEnsureRangeReturnsEmptyForAnInvalidWindow(): void
    {
        $transport = (new FakeTransport());
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $books = $this->await($store->ensureRange('lib-1', 200, 5, 2, 50));

        self::assertSame([], $books, 'start > end resolves empty without a request');
        self::assertSame(0, $transport->requestCount());
    }

    public function testEnsureRangeReusesCachedPages(): void
    {
        // Two overlapping windows in the same page hit the cache: one request.
        $transport = (new FakeTransport())->json(200, $this->booksPage(0, 50, 50));
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->ensureRange('lib-1', 200, 0, 5, 50));
        $second = $this->await($store->ensureRange('lib-1', 200, 3, 8, 50));

        self::assertSame([3, 4, 5, 6, 7, 8], array_keys($second));
        self::assertSame(1, $transport->requestCount(), 'the second window reuses the cached page');
    }

    // ---- book() --------------------------------------------------------

    public function testBookFetchesAndCachesWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())->json(200, $this->bookResponse());
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $book = $this->await($store->book('b1'));
        self::assertInstanceOf(Book::class, $book);
        self::assertSame('Dune', $book->title);
        self::assertSame('/api/v1/books/b1/cover?sig=abc', $book->coverUrl);
        self::assertSame(1, $transport->requestCount());
        self::assertStringEndsWith('/api/v1/books/b1', $transport->requestAt(0)['url']);

        // Within TTL → cached, no second request.
        $now = 1030.0;
        $cached = $this->await($store->book('b1'));
        self::assertSame('Dune', $cached->title);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testBookForceBypassesTheCache(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->bookResponse('First'))
            ->json(200, $this->bookResponse('Second'));
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->book('b1'));
        $forced = $this->await($store->book('b1', force: true));

        self::assertSame('Second', $forced->title);
        self::assertSame(2, $transport->requestCount(), 'force refetches even within TTL');
    }

    public function testConcurrentBookFetchesAreDeduplicated(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->book('b1');
        $second = $store->book('b1');

        self::assertSame($first, $second, 'a concurrent detail fetch shares the in-flight promise');
        self::assertSame(1, $transport->requestCount());
    }

    public function testFailedBookFetchClearsInFlightSoARetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->bookResponse());
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->book('b1'));
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $book = $this->await($store->book('b1'));
        self::assertInstanceOf(Book::class, $book, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    // ---- invalidate() --------------------------------------------------

    public function testInvalidateForcesARefetchOfBothCaches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->booksResponse('First'))
            ->json(200, $this->bookResponse('First'))
            ->json(200, $this->booksResponse('Second'))
            ->json(200, $this->bookResponse('Second'));
        $store = new BooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        self::assertSame('First', $this->await($store->page('lib-1', 24, 0))->books[0]->title);
        self::assertSame('First', $this->await($store->book('b1'))->title);

        $store->invalidate();

        self::assertSame('Second', $this->await($store->page('lib-1', 24, 0))->books[0]->title);
        self::assertSame('Second', $this->await($store->book('b1'))->title);
        self::assertSame(4, $transport->requestCount(), 'invalidate clears both the page and book caches');
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

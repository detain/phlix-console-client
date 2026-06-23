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

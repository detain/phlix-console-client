<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Api\Dto\AudiobookProgress;
use Phlix\Console\Store\AudiobooksStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class AudiobooksStoreTest extends TestCase
{
    /**
     * An `/audiobooks` page envelope whose audiobook ids equal their absolute
     * index, so a multi-page accumulation can be asserted positionally.
     */
    private function audiobooksPage(int $offset, int $count, int $limit = 100): array
    {
        $audiobooks = [];
        for ($i = 0; $i < $count; $i++) {
            $abs = $offset + $i;
            $audiobooks[] = ['id' => (string) $abs, 'title' => "Book {$abs}", 'metadata' => ['author' => 'A']];
        }

        return ['audiobooks' => $audiobooks, 'limit' => $limit, 'offset' => $offset];
    }

    /** A simple single-page `/audiobooks` envelope with one titled book. */
    private function audiobooksResponse(string $title = 'Dune'): array
    {
        return [
            'audiobooks' => [
                ['id' => 'a1', 'title' => $title, 'metadata' => ['author' => 'Frank Herbert']],
            ],
            'limit' => 100,
            'offset' => 0,
        ];
    }

    /** An `/audiobooks/{id}` detail envelope: `{audiobook: {…signed}}`. */
    private function audiobookResponse(string $title = 'Dune'): array
    {
        return ['audiobook' => [
            'id' => 'a1',
            'title' => $title,
            'author' => 'Frank Herbert',
            'duration_ms' => 75600000,
            'stream_url' => '/api/v1/audiobooks/a1/stream?sig=abc',
        ]];
    }

    /** An `/audiobooks/{id}/chapters` envelope. */
    private function chaptersResponse(string $firstTitle = 'One'): array
    {
        return ['chapters' => [
            ['index' => 0, 'title' => $firstTitle, 'start_ms' => 0, 'end_ms' => 1000, 'duration_ms' => 1000],
            ['index' => 1, 'title' => 'Two', 'start_ms' => 1000, 'end_ms' => 3000, 'duration_ms' => 2000],
        ]];
    }

    /** An `/audiobooks/{id}/progress` envelope: `{progress: {…}}`. */
    private function progressResponse(int $positionMs = 5000): array
    {
        return ['progress' => [
            'audiobook_id' => 'a1',
            'user_id' => 'u1',
            'position_ms' => $positionMs,
            'current_chapter_index' => 1,
            'completed_chapters' => [0],
            'percent_complete' => 10.0,
            'last_played_at' => 1700000000,
        ]];
    }

    // ---- all() ---------------------------------------------------------

    public function testAllFetchesASinglePageLibraryInOneRequest(): void
    {
        // A 3-book library: the single page is short (< 100) so the loop stops
        // after one fetch and returns all of it.
        $transport = (new FakeTransport())->json(200, $this->audiobooksPage(0, 3));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $all = $this->await($store->all('lib-1'));

        self::assertContainsOnlyInstancesOf(Audiobook::class, $all);
        self::assertSame(['0', '1', '2'], array_map(static fn (Audiobook $a): string => $a->id, $all));
        self::assertSame(1, $transport->requestCount(), 'a short first page needs only one fetch');
        self::assertStringContainsString('/api/v1/audiobooks?', $transport->requestAt(0)['url']);
        self::assertStringContainsString('library_id=lib-1', $transport->requestAt(0)['url']);
        self::assertStringContainsString('limit=100', $transport->requestAt(0)['url']);
        self::assertStringContainsString('offset=0', $transport->requestAt(0)['url']);
    }

    public function testAllLoopsEveryPageOfAMultiPageLibraryInOrder(): void
    {
        // Page 1 is full (100) → there may be more; page 2 is short (40) → stop.
        $transport = (new FakeTransport())
            ->json(200, $this->audiobooksPage(0, 100))
            ->json(200, $this->audiobooksPage(100, 40));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $all = $this->await($store->all('lib-1'));

        self::assertCount(140, $all, 'both pages are accumulated');
        self::assertSame('0', $all[0]->id, 'first page comes first');
        self::assertSame('99', $all[99]->id);
        self::assertSame('100', $all[100]->id, 'second page is concatenated in order');
        self::assertSame('139', $all[139]->id);
        self::assertSame(2, $transport->requestCount(), 'exactly two fetches: a full page then a short page');
        self::assertStringContainsString('offset=0', $transport->requestAt(0)['url']);
        self::assertStringContainsString('offset=100', $transport->requestAt(1)['url']);
    }

    public function testAllCachesWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())->json(200, $this->audiobooksPage(0, 3));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $first = $this->await($store->all('lib-1'));
        self::assertCount(3, $first);
        self::assertSame(1, $transport->requestCount());

        // Within TTL → cached, no second request.
        $now = 1030.0;
        $cached = $this->await($store->all('lib-1'));
        self::assertCount(3, $cached);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testAllRefetchesAfterTtlExpiry(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())
            ->json(200, $this->audiobooksPage(0, 2))
            ->json(200, $this->audiobooksPage(0, 5));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        self::assertCount(2, $this->await($store->all('lib-1')));

        $now = 1070.0;
        self::assertCount(5, $this->await($store->all('lib-1')));
        self::assertSame(2, $transport->requestCount(), 'refetched after TTL expiry');
    }

    public function testAllForceBypassesTheCache(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->audiobooksPage(0, 2))
            ->json(200, $this->audiobooksPage(0, 5));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->all('lib-1'));
        $forced = $this->await($store->all('lib-1', force: true));

        self::assertCount(5, $forced);
        self::assertSame(2, $transport->requestCount(), 'force refetches even within TTL');
    }

    public function testAllIsKeyedByLibraryId(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->audiobooksPage(0, 2))
            ->json(200, $this->audiobooksPage(0, 5));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $libA = $this->await($store->all('lib-A'));
        $libB = $this->await($store->all('lib-B'));

        self::assertCount(2, $libA);
        self::assertCount(5, $libB);
        self::assertSame(2, $transport->requestCount(), 'a different library is a different cache key');
        self::assertStringContainsString('library_id=lib-A', $transport->requestAt(0)['url']);
        self::assertStringContainsString('library_id=lib-B', $transport->requestAt(1)['url']);
    }

    public function testAllWithNullLibraryOmitsLibraryIdAndCachesUnderTheEmptyKey(): void
    {
        $transport = (new FakeTransport())->json(200, $this->audiobooksPage(0, 2));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->all(null));
        $this->await($store->all(null)); // cached

        self::assertSame(1, $transport->requestCount(), 'the null-library list is cached under the empty key');
        self::assertStringNotContainsString('library_id', $transport->requestAt(0)['url']);
    }

    public function testConcurrentAllFetchesAreDeduplicated(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->all('lib-1');
        $second = $store->all('lib-1');

        self::assertSame($first, $second, 'a concurrent fetch shares the in-flight promise');
        self::assertSame(1, $transport->requestCount(), 'only one underlying request');
    }

    public function testFailedAllFetchClearsInFlightSoARetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->audiobooksPage(0, 3));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->all('lib-1'));
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $all = $this->await($store->all('lib-1'));
        self::assertCount(3, $all, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    // ---- audiobook() ---------------------------------------------------

    public function testAudiobookFetchesAndCachesWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())->json(200, $this->audiobookResponse());
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $audiobook = $this->await($store->audiobook('a1'));
        self::assertInstanceOf(Audiobook::class, $audiobook);
        self::assertSame('Dune', $audiobook->title);
        self::assertSame('/api/v1/audiobooks/a1/stream?sig=abc', $audiobook->streamUrl);
        self::assertSame(1, $transport->requestCount());
        self::assertStringEndsWith('/api/v1/audiobooks/a1', $transport->requestAt(0)['url']);

        $now = 1030.0;
        $cached = $this->await($store->audiobook('a1'));
        self::assertSame('Dune', $cached->title);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testAudiobookForceBypassesTheCache(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->audiobookResponse('First'))
            ->json(200, $this->audiobookResponse('Second'));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->audiobook('a1'));
        $forced = $this->await($store->audiobook('a1', force: true));

        self::assertSame('Second', $forced->title);
        self::assertSame(2, $transport->requestCount(), 'force refetches even within TTL');
    }

    public function testConcurrentAudiobookFetchesAreDeduplicated(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->audiobook('a1');
        $second = $store->audiobook('a1');

        self::assertSame($first, $second, 'a concurrent detail fetch shares the in-flight promise');
        self::assertSame(1, $transport->requestCount());
    }

    public function testFailedAudiobookFetchClearsInFlightSoARetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->audiobookResponse());
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->audiobook('a1'));
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $audiobook = $this->await($store->audiobook('a1'));
        self::assertInstanceOf(Audiobook::class, $audiobook, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    // ---- chapters() ----------------------------------------------------

    public function testChaptersFetchesAndCachesWithinTtl(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = (new FakeTransport())->json(200, $this->chaptersResponse());
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, $clock);

        $chapters = $this->await($store->chapters('a1'));
        self::assertContainsOnlyInstancesOf(AudiobookChapter::class, $chapters);
        self::assertCount(2, $chapters);
        self::assertSame('One', $chapters[0]->title);
        self::assertSame(1, $transport->requestCount());
        self::assertStringEndsWith('/api/v1/audiobooks/a1/chapters', $transport->requestAt(0)['url']);

        $now = 1030.0;
        $cached = $this->await($store->chapters('a1'));
        self::assertCount(2, $cached);
        self::assertSame(1, $transport->requestCount(), 'cached within TTL');
    }

    public function testChaptersForceBypassesTheCache(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->chaptersResponse('First'))
            ->json(200, $this->chaptersResponse('Second'));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $this->await($store->chapters('a1'));
        $forced = $this->await($store->chapters('a1', force: true));

        self::assertSame('Second', $forced[0]->title);
        self::assertSame(2, $transport->requestCount(), 'force refetches even within TTL');
    }

    public function testConcurrentChaptersFetchesAreDeduplicated(): void
    {
        $transport = (new FakeTransport())->pending();
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $first = $store->chapters('a1');
        $second = $store->chapters('a1');

        self::assertSame($first, $second, 'a concurrent chapters fetch shares the in-flight promise');
        self::assertSame(1, $transport->requestCount());
    }

    public function testFailedChaptersFetchClearsInFlightSoARetryRefetches(): void
    {
        $transport = (new FakeTransport())
            ->fail(new \RuntimeException('boom'))
            ->json(200, $this->chaptersResponse());
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $error = null;
        try {
            $this->await($store->chapters('a1'));
        } catch (\Throwable $e) {
            $error = $e;
        }
        self::assertNotNull($error, 'first fetch rejects');

        $chapters = $this->await($store->chapters('a1'));
        self::assertContainsOnlyInstancesOf(AudiobookChapter::class, $chapters, 'retry succeeds because in-flight was cleared');
        self::assertSame(2, $transport->requestCount());
    }

    // ---- progress() / saveProgress() -----------------------------------

    public function testProgressDelegatesAndMaps(): void
    {
        $transport = (new FakeTransport())->json(200, $this->progressResponse(5000));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $progress = $this->await($store->progress('a1'));

        self::assertInstanceOf(AudiobookProgress::class, $progress);
        self::assertSame(5000, $progress->positionMs);
        self::assertSame([0], $progress->completedChapters);
        self::assertStringEndsWith('/api/v1/audiobooks/a1/progress', $transport->requestAt(0)['url']);
        self::assertSame('GET', $transport->requestAt(0)['method']);
    }

    public function testProgressIsNotCached(): void
    {
        // Two calls → two fetches (progress must always be fresh).
        $transport = (new FakeTransport())
            ->json(200, $this->progressResponse(5000))
            ->json(200, $this->progressResponse(9000));
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        self::assertSame(5000, $this->await($store->progress('a1'))->positionMs);
        self::assertSame(9000, $this->await($store->progress('a1'))->positionMs);
        self::assertSame(2, $transport->requestCount(), 'progress is never cached');
    }

    public function testSaveProgressPostsTheBodyAndMapsTheReturnedProgress(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'message' => 'saved',
            'progress' => [
                'audiobook_id' => 'a1',
                'user_id' => 'u1',
                'position_ms' => 12345,
                'current_chapter_index' => 2,
                'completed_chapters' => [0, 1],
                'percent_complete' => 25.0,
                'last_played_at' => 1700000001,
            ],
        ]);
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        $progress = $this->await($store->saveProgress('a1', 12345, 2, [0, 1], 25.0));

        self::assertInstanceOf(AudiobookProgress::class, $progress);
        self::assertSame(12345, $progress->positionMs);
        self::assertSame(2, $progress->currentChapterIndex);
        self::assertSame([0, 1], $progress->completedChapters);
        self::assertSame(25.0, $progress->percentComplete);

        $req = $transport->requestAt(0);
        self::assertSame('POST', $req['method']);
        self::assertStringEndsWith('/api/v1/audiobooks/a1/progress', $req['url']);
        $body = json_decode($req['body'], true);
        self::assertSame(12345, $body['position_ms']);
        self::assertSame(2, $body['current_chapter_index']);
        self::assertSame([0, 1], $body['completed_chapters']);
        // JSON has no float/int distinction, so 25.0 encodes as `25`; assert the
        // numeric value rather than the round-tripped PHP type.
        self::assertEqualsWithDelta(25.0, $body['percent_complete'], 0.0001);
    }

    // ---- invalidate() --------------------------------------------------

    public function testInvalidateForcesARefetchOfEveryCache(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->audiobooksPage(0, 2))   // all() #1
            ->json(200, $this->audiobookResponse('First'))   // audiobook() #1
            ->json(200, $this->chaptersResponse('First'))    // chapters() #1
            ->json(200, $this->audiobooksPage(0, 5))   // all() #2
            ->json(200, $this->audiobookResponse('Second'))  // audiobook() #2
            ->json(200, $this->chaptersResponse('Second'));  // chapters() #2
        $store = new AudiobooksStore(new ApiClient('https://srv', $transport), 60.0, static fn (): float => 1000.0);

        self::assertCount(2, $this->await($store->all('lib-1')));
        self::assertSame('First', $this->await($store->audiobook('a1'))->title);
        self::assertSame('First', $this->await($store->chapters('a1'))[0]->title);

        $store->invalidate();

        self::assertCount(5, $this->await($store->all('lib-1')));
        self::assertSame('Second', $this->await($store->audiobook('a1'))->title);
        self::assertSame('Second', $this->await($store->chapters('a1'))[0]->title);
        self::assertSame(6, $transport->requestCount(), 'invalidate clears the list, detail and chapter caches');
    }

    /**
     * Settle a promise on the event loop. The store wraps the synchronous
     * FakeTransport in react Deferreds, so a settled promise may have enqueued a
     * futureTick — flush it with one immediate tick so no residual work leaks
     * into a later test's Loop::run().
     */
    private function await(PromiseInterface $promise, float $timeout = 5.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($value) use (&$state): void {
                $state['value'] = $value;
                $state['done'] = true;
                Loop::stop();
            },
            function ($error) use (&$state): void {
                $state['error'] = $error;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if ($state['done']) {
            Loop::futureTick(static fn () => Loop::stop());
            Loop::run();
        } else {
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

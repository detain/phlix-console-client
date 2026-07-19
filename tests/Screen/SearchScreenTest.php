<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\GridPosterLoadedMsg;
use Phlix\Console\Msg\LibraryFailedMsg;
use Phlix\Console\Msg\MediaRangeLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\SearchDebouncedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Screen\CapturesSlash;
use Phlix\Console\Screen\SearchScreen;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Mosaic\Mosaic;

final class SearchScreenTest extends TestCase
{
    public function testImplementsCapturesSlashSoSlashTypesInsteadOfReopening(): void
    {
        self::assertInstanceOf(CapturesSlash::class, $this->screenWith(new FakeTransport()));
    }

    public function testInitShowsThePromptAndFetchesNothing(): void
    {
        $screen = $this->screenWith(new FakeTransport());

        self::assertNull($screen->init(), 'no fetch until the user types');
        self::assertFalse($screen->hasSearched());
        self::assertStringContainsString('Type to search', $screen->view());
    }

    public function testTypingDebouncesAndAppliesOnTheMatchingSequence(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->pageResponse(0, 3, 3, 50)));

        [$typed, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        self::assertSame('m', $typed->searchText());
        self::assertInstanceOf(\Closure::class, $cmd, 'a debounce tick is scheduled');

        // The debounce fires → the query is built and a fetch issued.
        [$applied, $fetch] = $typed->update(new SearchDebouncedMsg(1));
        self::assertSame('m', $applied->query()->search);
        self::assertTrue($applied->hasSearched());
        self::assertInstanceOf(MediaRangeLoadedMsg::class, $this->runCmd($fetch));
    }

    public function testAStaleDebounceTickIsIgnored(): void
    {
        $screen = $this->screenWith(new FakeTransport());
        [$typed] = $screen->update(new KeyMsg(KeyType::Char, 'm')); // seq 1

        // A debounce for an older keystroke (seq 0) is a no-op.
        [$same, $cmd] = $typed->update(new SearchDebouncedMsg(0));

        self::assertFalse($same->hasSearched());
        self::assertNull($cmd);
    }

    public function testResultsPopulateTheGrid(): void
    {
        $screen = $this->runSearch('matrix', 3);

        self::assertSame(3, $screen->grid()->total());
        self::assertStringContainsString('3 results', $screen->view());
    }

    public function testSingularResultCount(): void
    {
        $screen = $this->runSearch('matrix', 1);

        self::assertStringContainsString('1 result', $screen->view());
        self::assertStringNotContainsString('1 results', $screen->view());
    }

    public function testMatchingResultTitlesAreFuzzyHighlighted(): void
    {
        // Titles are 'Item 0', 'Item 1', … so searching 'Item' matches the run.
        $screen = $this->runSearch('Item', 3);
        $card = $screen->grid()->item(0);

        self::assertNotNull($card);
        self::assertNotNull($card->styledTitle, 'a matching result carries a highlighted title');
        self::assertStringContainsString("\e[1m", $card->styledTitle, 'the matched run is styled (bold)');
        self::assertStringContainsString('Item', $card->styledTitle);
        self::assertSame('Item 0', $card->title, 'the plain title is kept for identity/sort');
    }

    public function testNonMatchingTitlesAreLeftPlain(): void
    {
        // 'zzzz' has no alignment in 'Item N' → no fuzzy match → no styled title.
        $screen = $this->runSearch('zzzz', 2);
        $card = $screen->grid()->item(0);

        self::assertNotNull($card);
        self::assertNull($card->styledTitle, 'an unmatched title is left plain');
    }

    public function testNoResultsShowsTheEmptyState(): void
    {
        $screen = $this->runSearch('zzzz', 0);

        self::assertStringContainsString('No results for "zzzz"', $screen->view());
    }

    public function testEnterOpensTheFocusedResult(): void
    {
        $screen = $this->runSearch('matrix', 3);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(OpenDetailMsg::class, $msg);
        self::assertSame('0', $msg->id, 'the first result is focused');
    }

    public function testEscapeNavigatesBack(): void
    {
        [, $cmd] = $this->screenWith(new FakeTransport())->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($cmd));
    }

    public function testClearingTheQueryReturnsToThePrompt(): void
    {
        $screen = $this->runSearch('matrix', 3);
        self::assertTrue($screen->hasSearched());

        // Backspace the whole query, then let the debounce settle.
        foreach (range(1, 6) as $i) {
            [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Backspace));
        }
        self::assertSame('', $screen->searchText());

        [$cleared] = $screen->update(new SearchDebouncedMsg(12)); // 6 chars typed + 6 backspaces
        self::assertFalse($cleared->hasSearched());
        self::assertStringContainsString('Type to search', $cleared->view());
    }

    public function testBackspaceOnAnEmptyQueryIsANoOp(): void
    {
        [$next, $cmd] = $this->screenWith(new FakeTransport())->update(new KeyMsg(KeyType::Backspace));

        self::assertSame('', $next->searchText());
        self::assertNull($cmd);
    }

    public function testAuthErrorDuringSearchBecomesSessionExpired(): void
    {
        $screen = $this->screenWith((new FakeTransport())->fail(new \Phlix\Console\Api\AuthError('nope')));
        [$typed] = $screen->update(new KeyMsg(KeyType::Char, 'm'));

        [, $fetch] = $typed->update(new SearchDebouncedMsg(1));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($fetch));
    }

    public function testTransientFailureBeforeLoadShowsAnError(): void
    {
        $screen = $this->screenWith(new FakeTransport());

        [$next] = $screen->update(new LibraryFailedMsg('Could not run your search.'));

        self::assertStringContainsString('Could not run your search.', $next->view());
    }

    public function testArrowKeyMovesTheResultGrid(): void
    {
        $screen = $this->runSearch('matrix', 200);
        $before = $screen->grid()->cursorIndex();

        [$moved] = $screen->update(new KeyMsg(KeyType::Down));

        self::assertNotSame($before, $moved->grid()->cursorIndex(), 'Down moves the grid cursor');
    }

    public function testResizeReflowsTheGrid(): void
    {
        $screen = $this->runSearch('matrix', 200);

        [$resized] = $screen->update(new WindowSizeMsg(80, 24));

        self::assertStringContainsString('Search', $resized->view());
    }

    public function testSpaceTypesIntoTheQuery(): void
    {
        [$typed, $cmd] = $this->screenWith(new FakeTransport())->update(new KeyMsg(KeyType::Space));

        self::assertSame(' ', $typed->searchText());
        self::assertInstanceOf(\Closure::class, $cmd, 'a space also debounces');
    }

    public function testSearchingIndicatorBeforeResultsArrive(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->pageResponse(0, 3, 3, 50)));
        [$typed] = $screen->update(new KeyMsg(KeyType::Char, 'm'));

        // applySearch has run but the range hasn't resolved yet.
        [$applied] = $typed->update(new SearchDebouncedMsg(1));

        self::assertTrue($applied->hasSearched());
        self::assertStringContainsString('Searching', $applied->view());
    }

    /** A screen with a query issued but its first page still in flight. */
    private function searching(): \Phlix\Console\Screen\SearchScreen
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->pageResponse(0, 3, 3, 50)));
        [$typed] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$applied] = $typed->update(new SearchDebouncedMsg(1));

        return $applied;
    }

    public function testTheInitialPromptIsNotALoadingState(): void
    {
        // Before any search, the "type to search" prompt is shown — not loading,
        // so no shimmer band (▒) and isLoading() is false.
        $screen = $this->screenWith(new FakeTransport())->withShimmerPhase(5);

        self::assertFalse($screen->isLoading(), 'the prompt is not a loading state');
        self::assertStringNotContainsString('▒', $screen->view(), 'no shimmer before a search runs');
    }

    public function testTheSearchingBodyShowsTheShimmerSkeleton(): void
    {
        $view = $this->searching()->withShimmerPhase(5)->view();

        self::assertStringContainsString('Searching', $view, 'the input + status lines remain');
        self::assertStringContainsString('▒', $view, 'the in-flight results area shows the shimmer band');
    }

    public function testSearchScreenIsLoadingWhileAQueryIsInFlight(): void
    {
        self::assertTrue($this->searching()->isLoading(), 'a query in flight is a loading state');
    }

    public function testTheSkeletonIsGoneOnceResultsLand(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->pageResponse(0, 3, 3, 50)));
        [$typed] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$applied, $fetch] = $typed->update(new SearchDebouncedMsg(1));
        $range = $this->runCmd($fetch);
        [$loaded] = $applied->update($range);

        self::assertFalse($loaded->isLoading(), 'results landed → no longer loading');
        self::assertStringNotContainsString('▒', $loaded->withShimmerPhase(5)->view(), 'the shimmer band is gone');
        self::assertStringContainsString('3 results', $loaded->view());
    }

    public function testVisibleResultsSchedulePosterLoadsAndAttach(): void
    {
        $transport = (new FakeTransport())->json(200, $this->pageResponse(0, 3, 3, 50, posters: true));
        $screen = $this->screenWith($transport);
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$screen, $fetch] = $screen->update(new SearchDebouncedMsg(1));
        $range = $this->runCmd($fetch);

        [$loaded, $posterCmd] = $screen->update($range);
        self::assertInstanceOf(\Closure::class, $posterCmd, 'visible cells with posters are scheduled');

        // A resolved poster attaches to its card; an unknown index is a no-op.
        [$withPoster] = $loaded->update(new GridPosterLoadedMsg(0, "\e[7m \e[0m"));
        self::assertTrue($withPoster->grid()->item(0)?->hasPoster());

        [$same] = $loaded->update(new GridPosterLoadedMsg(9999, 'x'));
        self::assertFalse($same->grid()->item(0)?->hasPoster() ?? false);

        // Moving within the loaded window still schedules loads for poster-less cells.
        [, $moveCmd] = $loaded->update(new KeyMsg(KeyType::Right));
        self::assertInstanceOf(\Closure::class, $moveCmd);
    }

    /**
     * Empty string posterUrl must not produce a poster load command — it must
     * be skipped silently just like a null URL, to avoid "URL scheme unknown"
     * errors from the poster loader when an empty string is passed.
     */
    public function testEmptyStringPosterUrlIsSkippedAndDoesNotCrash(): void
    {
        // Create a search response where items have empty string poster_url.
        $transport = (new FakeTransport())->json(200, [
            'items' => [
                ['id' => '0', 'name' => 'Movie 0', 'type' => 'movie', 'poster_url' => ''],
                ['id' => '1', 'name' => 'Movie 1', 'type' => 'movie', 'poster_url' => 'https://p/1.jpg'],
            ],
            'total' => 2,
            'limit' => 50,
            'offset' => 0,
        ]);
        $screen = $this->screenWith($transport);
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$screen, $fetch] = $screen->update(new SearchDebouncedMsg(1));
        $range = $this->runCmd($fetch);

        [$loaded, $posterCmd] = $screen->update($range);

        // The item with empty string posterUrl must not produce a poster load.
        // Only the item with a valid poster URL should be loaded.
        $posterMsgs = $this->runBatch($posterCmd);
        self::assertCount(1, $posterMsgs, 'only the valid poster URL is loaded, the empty string is skipped');
    }

    /**
     * A relative URL (no scheme, e.g. /poster.jpg) is now resolved to an
     * absolute URL (e.g. https://srv/poster.jpg) and SHOULD be loaded — it is
     * NOT skipped silently anymore. The resolveUrl() fix (B4) enables this.
     */
    public function testRelativeUrlPosterIsResolvedAndLoaded(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'items' => [
                ['id' => '0', 'name' => 'Movie 0', 'type' => 'movie', 'poster_url' => '/poster.jpg'],
                ['id' => '1', 'name' => 'Movie 1', 'type' => 'movie', 'poster_url' => 'https://p/1.jpg'],
            ],
            'total' => 2,
            'limit' => 50,
            'offset' => 0,
        ]);
        $screen = $this->screenWith($transport);
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$screen, $fetch] = $screen->update(new SearchDebouncedMsg(1));
        $range = $this->runCmd($fetch);

        [$loaded, $posterCmd] = $screen->update($range);

        // Both the resolved relative URL and the valid absolute URL should be loaded.
        $posterMsgs = $this->runBatch($posterCmd);
        self::assertCount(2, $posterMsgs, 'relative URL is resolved to https://srv/poster.jpg and loaded along with the other valid URL');
    }

    /**
     * A malformed URL (e.g. not-a-valid-url) must not produce a poster load
     * command — it is skipped silently, treated the same as a missing poster.
     */
    public function testMalformedUrlPosterIsSkippedSilently(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'items' => [
                ['id' => '0', 'name' => 'Movie 0', 'type' => 'movie', 'poster_url' => 'not-a-valid-url'],
                ['id' => '1', 'name' => 'Movie 1', 'type' => 'movie', 'poster_url' => 'https://p/1.jpg'],
            ],
            'total' => 2,
            'limit' => 50,
            'offset' => 0,
        ]);
        $screen = $this->screenWith($transport);
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$screen, $fetch] = $screen->update(new SearchDebouncedMsg(1));
        $range = $this->runCmd($fetch);

        [$loaded, $posterCmd] = $screen->update($range);

        // Only the item with a valid poster URL should be loaded.
        $posterMsgs = $this->runBatch($posterCmd);
        self::assertCount(1, $posterMsgs, 'only the valid poster URL is loaded, the malformed URL is skipped');
    }

    /**
     * A URL with a non-http(s) scheme (e.g. ftp:// or javascript:) must not
     * produce a poster load command — it is skipped silently, treated the same
     * as a missing poster.
     */
    public function testNonHttpSchemePosterIsSkippedSilently(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'items' => [
                ['id' => '0', 'name' => 'Movie 0', 'type' => 'movie', 'poster_url' => 'ftp://cdn.example.com/file.jpg'],
                ['id' => '1', 'name' => 'Movie 1', 'type' => 'movie', 'poster_url' => 'https://p/1.jpg'],
            ],
            'total' => 2,
            'limit' => 50,
            'offset' => 0,
        ]);
        $screen = $this->screenWith($transport);
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$screen, $fetch] = $screen->update(new SearchDebouncedMsg(1));
        $range = $this->runCmd($fetch);

        [$loaded, $posterCmd] = $screen->update($range);

        // Only the item with a valid poster URL should be loaded.
        $posterMsgs = $this->runBatch($posterCmd);
        self::assertCount(1, $posterMsgs, 'only the valid poster URL is loaded, the non-http scheme is skipped');
    }

    public function testPagingAndJumpKeysMoveTheGrid(): void
    {
        $screen = $this->runSearch('matrix', 200);

        [$end] = $screen->update(new KeyMsg(KeyType::End));
        self::assertSame(199, $end->grid()->cursorIndex());

        [$home] = $end->update(new KeyMsg(KeyType::Home));
        self::assertSame(0, $home->grid()->cursorIndex());

        [$down] = $screen->update(new KeyMsg(KeyType::PageDown));
        self::assertGreaterThan(0, $down->grid()->cursorIndex());
    }

    public function testScrollingBeyondTheLoadedWindowFetchesMore(): void
    {
        $screen = $this->runSearch('matrix', 200);

        // Jumping to the end is outside the first page → a new range is fetched.
        [, $cmd] = $screen->update(new KeyMsg(KeyType::End));

        self::assertInstanceOf(\Closure::class, $cmd, 'a window miss issues a fetch');
    }

    public function testSupersededRangeResultIsIgnored(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->pageResponse(0, 3, 3, 50)));
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$screen, $fetch] = $screen->update(new SearchDebouncedMsg(1)); // generation 1
        $loaded = $this->runCmd($fetch);
        self::assertInstanceOf(MediaRangeLoadedMsg::class, $loaded);

        // The same payload tagged with an older generation is dropped.
        [$ignored] = $screen->update(new MediaRangeLoadedMsg($loaded->range, 0));

        self::assertSame(0, $ignored->grid()->total(), 'a stale generation does not populate the grid');
    }

    public function testBreadcrumbLabelAndTrail(): void
    {
        $screen = $this->screenWith(new FakeTransport());

        self::assertSame('Search', $screen->crumbLabel());
        self::assertStringContainsString('Movies', $screen->withCrumbs(['Home', 'Movies'])->view());
    }

    public function testUnknownKeysAndMessagesAreNoOps(): void
    {
        $screen = $this->screenWith(new FakeTransport());

        [$afterKey, $keyCmd] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertNull($keyCmd);
        self::assertSame('', $afterKey->searchText());

        // A message the screen does not handle falls through untouched.
        [$afterMsg, $msgCmd] = $screen->update(new NavigateBackMsg());
        self::assertNull($msgCmd);
        self::assertNull($afterMsg->error());
    }

    // ---- helpers -------------------------------------------------------

    private function screenWith(FakeTransport $transport, ?PosterLoader $posters = null): SearchScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new SearchScreen(
            new MediaStore($api),
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            $api->baseUrl(),
            cols: 120,
            rows: 40,
        );
    }

    /** Type a query, fire its debounce, and resolve the fetch into the grid. */
    private function runSearch(string $query, int $total): SearchScreen
    {
        $transport = (new FakeTransport())->json(200, $this->pageResponse(0, min($total, 50), $total, 50));
        $screen = $this->screenWith($transport);

        foreach (mb_str_split($query) as $rune) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Char, $rune));
        }

        [$screen, $fetch] = $screen->update(new SearchDebouncedMsg(mb_strlen($query)));
        self::assertInstanceOf(\Closure::class, $fetch, 'a non-empty query fetches');
        $range = $this->runCmd($fetch);
        self::assertInstanceOf(MediaRangeLoadedMsg::class, $range);

        return $screen->update($range)[0];
    }

    /**
     * @return array{items: list<array<string,mixed>>, total: int, limit: int, offset: int}
     */
    private function pageResponse(int $offset, int $count, int $total, int $limit, bool $posters = false): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $abs = $offset + $i;
            $row = ['id' => (string) $abs, 'name' => 'Item ' . $abs, 'type' => 'movie'];
            if ($posters) {
                $row['poster_url'] = "https://p/{$abs}.jpg";
            }
            $items[] = $row;
        }

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    private function runCmd(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }

        $result = $cmd();
        if ($result instanceof BatchMsg) {
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                if ($msg !== null) {
                    return $msg;
                }
            }

            return null;
        }
        if ($result instanceof AsyncCmd) {
            return $this->await($result->promise);
        }

        return $result instanceof Msg ? $result : null;
    }

    private function await(PromiseInterface $promise, float $timeout = 5.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $timer = null;
        $settle = static function () use (&$timer): void {
            if ($timer !== null) {
                Loop::cancelTimer($timer);
                $timer = null;
            }
            Loop::stop();
        };
        $promise->then(
            function ($v) use (&$state, $settle): void {
                $state['value'] = $v;
                $state['done'] = true;
                $settle();
            },
            function ($e) use (&$state, $settle): void {
                $state['error'] = $e;
                $state['done'] = true;
                $settle();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
        }

        if (!$state['done']) {
            throw new \RuntimeException('cmd did not settle in time');
        }
        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }

    /** @return list<Msg> */
    private function runBatch(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }

        $result = $cmd();

        if ($result instanceof BatchMsg) {
            $msgs = [];
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                if ($msg !== null) {
                    $msgs[] = $msg;
                }
            }

            return $msgs;
        }

        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? [$msg] : [];
        }

        return $result instanceof Msg ? [$result] : [];
    }
}

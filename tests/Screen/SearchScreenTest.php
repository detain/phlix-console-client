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
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Mosaic\Mosaic;

final class SearchScreenTest extends TestCase
{
    private ?SocketServer $socket = null;

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

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
     * An empty (or null) posterUrl is the ONLY case skipped by loadPostersIn:
     * after resolveUrl it stays empty, so no load Cmd is scheduled. (A crash-free
     * skip — no "URL scheme unknown" from the loader.)
     */
    public function testEmptyStringPosterUrlIsSkippedAndDoesNotCrash(): void
    {
        self::assertSame(0, $this->scheduledPosterLoads($this->posterCmdFor('')), 'an empty posterUrl schedules no poster load');
    }

    /**
     * A relative URL (no scheme, e.g. /cover.png) is resolved to an absolute URL
     * (baseUrl + path) and IS loaded — it is NOT dropped. The base IS the local
     * cover server, so the resolved URL genuinely loads and yields a poster msg,
     * proving the relative path was resolved against the base (an unresolved
     * "/cover.png" has no host and could not load).
     */
    public function testRelativeUrlPosterIsResolvedAndLoaded(): void
    {
        $port = $this->startCoverServer();
        $posterMsgs = array_filter(
            $this->runBatch($this->posterCmdFor('/cover.png', "http://127.0.0.1:{$port}")),
            static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg,
        );
        self::assertCount(1, $posterMsgs, 'the relative posterUrl is resolved against the base and loaded');
    }

    /**
     * A scheme-less string (e.g. not-a-valid-url) is treated as a relative path:
     * resolveUrl prefixes the base, so the resolved URL is http(s) and a load IS
     * scheduled against the base (it is not silently dropped).
     */
    public function testMalformedUrlPosterIsResolvedAgainstBase(): void
    {
        self::assertSame(1, $this->scheduledPosterLoads($this->posterCmdFor('not-a-valid-url')), 'a scheme-less posterUrl is base-prefixed and a load is scheduled');
    }

    /**
     * A non-http(s) scheme (e.g. ftp://…) does not match the absolute-URL guard,
     * so resolveUrl treats it as a relative path and prefixes the base; the
     * resolved URL is http(s) and a load IS scheduled against the base.
     */
    public function testNonHttpSchemePosterIsResolvedAgainstBase(): void
    {
        self::assertSame(1, $this->scheduledPosterLoads($this->posterCmdFor('ftp://cdn.example.com/file.jpg')), 'a non-http(s) posterUrl is base-prefixed and a load is scheduled');
    }

    /**
     * Drive a one-item search to the poster-load Cmd produced for that single
     * item's poster_url (or null if none was scheduled).
     */
    private function posterCmdFor(string $posterUrl, string $base = 'https://srv'): ?\Closure
    {
        $transport = (new FakeTransport())->json(200, [
            'items' => [['id' => '0', 'name' => 'Movie 0', 'type' => 'movie', 'poster_url' => $posterUrl]],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ]);
        $screen = $this->screenWith($transport, new PosterLoader(Mosaic::halfBlock()), $base);
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [$screen, $fetch] = $screen->update(new SearchDebouncedMsg(1));
        $range = $this->runCmd($fetch);

        return $screen->update($range)[1];
    }

    /** Count the poster-load Cmds a (possibly null) batch schedules, without running them. */
    private function scheduledPosterLoads(?\Closure $cmd): int
    {
        if ($cmd === null) {
            return 0;
        }
        $result = $cmd();

        return $result instanceof BatchMsg ? count($result->cmds) : 1;
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

    private function screenWith(FakeTransport $transport, ?PosterLoader $posters = null, string $base = 'https://srv'): SearchScreen
    {
        $api = new ApiClient($base, $transport);

        return new SearchScreen(
            new MediaStore($api),
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            $api->baseUrl(),
            cols: 120,
            rows: 40,
        );
    }

    /**
     * A tiny local HTTP image server (mirrors the Photos screen tests). Returns a
     * valid PNG for any path except one containing "nope" (which 404s). Lets a
     * resolved poster URL be genuinely loaded so the test can prove resolution +
     * scheduling instead of counting loads against unreachable fake hosts.
     */
    private function startCoverServer(): int
    {
        $img = imagecreatetruecolor(8, 12);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 70, 120, 180));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        $server = new HttpServer(static function (ServerRequestInterface $r) use ($png): Response {
            return str_contains((string) $r->getUri()->getPath(), 'nope')
                ? new Response(404, [], 'not found')
                : new Response(200, ['Content-Type' => 'image/png'], $png);
        });
        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);

        return (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
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

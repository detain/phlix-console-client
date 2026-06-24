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
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Store\MediaRange;
use Phlix\Console\Screen\LibraryScreen;
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
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Toast\ToastType;

final class LibraryScreenTest extends TestCase
{
    private ?SocketServer $socket = null;

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    private function screenWith(FakeTransport $transport, ?PosterLoader $posters = null): LibraryScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new LibraryScreen(
            'lib-a',
            'Movies',
            new MediaStore($api),
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            cols: 120,
            rows: 40,
        );
    }

    /** A `/media` page whose item ids equal their absolute index. */
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

    /** Build a screen whose initial window has loaded $total items (range only). */
    private function loadedScreen(int $total = 200, bool $posters = false): LibraryScreen
    {
        $transport = (new FakeTransport())->json(200, $this->pageResponse(0, 50, $total, 50, $posters));
        $screen = $this->screenWith($transport);
        $range = $this->runBatch($screen->init())[0];
        self::assertInstanceOf(MediaRangeLoadedMsg::class, $range);

        return $screen->update($range)[0];
    }

    /** An A–Z index where #=0/10, A=10/90, M=100/100 (the rest empty), total 200. */
    private function letterIndexResponse(): array
    {
        $letters = [['letter' => '#', 'offset' => 0, 'count' => 10]];
        foreach (range('A', 'Z') as $letter) {
            if ($letter === 'A') {
                $letters[] = ['letter' => 'A', 'offset' => 10, 'count' => 90];
            } elseif ($letter === 'M') {
                $letters[] = ['letter' => 'M', 'offset' => 100, 'count' => 100];
            } else {
                $letters[] = ['letter' => $letter, 'offset' => 200, 'count' => 0];
            }
        }

        return ['letters' => $letters, 'total' => 200];
    }

    /** Build a screen with both the first window and the A–Z index loaded. */
    private function loadedWithIndex(): LibraryScreen
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pageResponse(0, 50, 200, 50))
            ->json(200, $this->letterIndexResponse());
        $screen = $this->screenWith($transport);
        foreach ($this->runBatch($screen->init()) as $msg) {
            [$screen] = $screen->update($msg);
        }

        return $screen;
    }

    public function testInitFetchesTheFirstWindowClippedToTheViewport(): void
    {
        $transport = (new FakeTransport())->json(200, $this->pageResponse(0, 50, 200, 50));
        $msgs = $this->runBatch($this->screenWith($transport)->init());

        self::assertInstanceOf(MediaRangeLoadedMsg::class, $msgs[0]);
        self::assertSame(200, $msgs[0]->range->total);
        self::assertArrayHasKey(0, $msgs[0]->range->items);
        self::assertArrayNotHasKey(49, $msgs[0]->range->items, 'clipped to the visible window, not the whole page');
    }

    public function testLoadingViewBeforeData(): void
    {
        $view = $this->screenWith(new FakeTransport())->view();

        self::assertStringContainsString('Loading', $view);
        self::assertStringContainsString('Movies', $view);
    }

    public function testLoadingBodyShowsTheShimmerSkeleton(): void
    {
        // The shimmer band's mid glyph (▒) is unique to the Skeleton; at a
        // mid-sweep phase it is on screen in the loading body.
        $view = $this->screenWith(new FakeTransport())->withShimmerPhase(5)->view();

        self::assertStringContainsString('▒', $view, 'the loading body renders the shimmer band');
    }

    public function testTheSkeletonIsGoneOnceLoaded(): void
    {
        $view = $this->loadedScreen(200)->withShimmerPhase(5)->view();

        self::assertStringNotContainsString('▒', $view, 'the shimmer band disappears once the grid is loaded');
        self::assertStringContainsString('200 items', $view);
    }

    public function testIsLoadingIsTrueBeforeLoadAndFalseAfter(): void
    {
        self::assertTrue($this->screenWith(new FakeTransport())->isLoading(), 'a fresh screen is loading');
        self::assertFalse($this->loadedScreen(200)->isLoading(), 'a populated screen is not loading');
    }

    public function testWithShimmerPhaseAdvancesTheLoadingBody(): void
    {
        $screen = $this->screenWith(new FakeTransport());

        self::assertNotSame(
            $screen->withShimmerPhase(0)->view(),
            $screen->withShimmerPhase(5)->view(),
            'the loading skeleton reflects the shimmer phase',
        );
    }

    public function testRangeLoadedPopulatesTheGrid(): void
    {
        $loaded = $this->loadedScreen(200);

        self::assertTrue($loaded->isLoaded());
        self::assertSame(200, $loaded->grid()->total());
        self::assertSame('0', $loaded->grid()->item(0)?->id);
        self::assertStringContainsString('200 items', $loaded->view());
    }

    public function testEmptyLibraryRenders(): void
    {
        $loaded = $this->loadedScreen(0);

        self::assertStringContainsString('No items', $loaded->view());
    }

    public function testNavigationMovesTheCursor(): void
    {
        $loaded = $this->loadedScreen(200);

        [$right] = $loaded->update(new KeyMsg(KeyType::Right));
        self::assertSame(1, $right->grid()->cursorIndex());

        [$down] = $right->update(new KeyMsg(KeyType::Down));
        self::assertSame(1 + $loaded->grid()->columns(), $down->grid()->cursorIndex());

        [$end] = $down->update(new KeyMsg(KeyType::End));
        self::assertSame(199, $end->grid()->cursorIndex());

        [$home] = $end->update(new KeyMsg(KeyType::Home));
        self::assertSame(0, $home->grid()->cursorIndex());
    }

    public function testMovingWithinTheLoadedWindowDoesNotRefetch(): void
    {
        $loaded = $this->loadedScreen(200);

        // Right stays in row 0 (already requested at init) → no fetch, no posters.
        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Right));

        self::assertNull($cmd, 'no Cmd when the window is already covered and there are no posters to load');
    }

    public function testScrollingToANewWindowIssuesAFetch(): void
    {
        $loaded = $this->loadedScreen(200);

        [$end, $cmd] = $loaded->update(new KeyMsg(KeyType::End));
        self::assertSame(199, $end->grid()->cursorIndex());
        self::assertInstanceOf(\Closure::class, $cmd, 'the bottom window (a new range) must be fetched');
    }

    public function testSupersededRangeResultIsDropped(): void
    {
        $loaded = $this->loadedScreen(200);
        $stale = new MediaRangeLoadedMsg(new \Phlix\Console\Store\MediaRange([], 999), 999);

        [$same] = $loaded->update($stale);

        self::assertSame(200, $same->grid()->total(), 'a result from a superseded generation is ignored');
    }

    public function testEscEmitsNavigateBack(): void
    {
        [, $cmd] = $this->loadedScreen()->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    public function testEnterOpensTheFocusedCellDetail(): void
    {
        // loadedScreen() seeds items whose id == their absolute index; the cursor
        // starts on the first cell.
        [, $cmd] = $this->loadedScreen()->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenDetailMsg::class, $msg);
        self::assertSame('0', $msg->id);
        self::assertSame('Item 0', $msg->name);
    }

    public function testEnterOnAnUnloadedCellIsANoOp(): void
    {
        // A fresh screen before any range resolves has only skeleton cells.
        $screen = $this->screenWith((new FakeTransport())->pending());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd, 'no detail opens for a not-yet-loaded cell');
    }

    public function testLetterKeyIsAJumpNotAQuit(): void
    {
        // In a library, letters jump (quit is Ctrl-C / Esc-then-Browse), so 'q'
        // must NOT quit. With no index loaded it is simply consumed.
        [, $cmd] = $this->loadedScreen()->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertNotInstanceOf(QuitMsg::class, $cmd?->__invoke());
    }

    public function testInitFetchesTheLetterIndex(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pageResponse(0, 50, 200, 50))
            ->json(200, $this->letterIndexResponse());

        $msgs = $this->runBatch($this->screenWith($transport)->init());
        $types = array_map('get_class', $msgs);

        self::assertContains(MediaRangeLoadedMsg::class, $types);
        self::assertContains(\Phlix\Console\Msg\LetterIndexLoadedMsg::class, $types);
    }

    public function testLetterKeyJumpsTheGridToThatBucket(): void
    {
        $screen = $this->loadedWithIndex();
        self::assertSame(0, $screen->grid()->cursorIndex());

        [$jumped] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        self::assertSame(100, $jumped->grid()->cursorIndex(), 'M begins at offset 100');

        [$back] = $jumped->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertSame(10, $back->grid()->cursorIndex(), 'A begins at offset 10');
    }

    public function testDigitJumpsToTheNonAlphabeticBucket(): void
    {
        $screen = $this->loadedWithIndex()->update(new KeyMsg(KeyType::Char, 'm'))[0]; // cursor 100
        [$hash] = $screen->update(new KeyMsg(KeyType::Char, '7'));

        self::assertSame(0, $hash->grid()->cursorIndex(), 'digits jump to the # bucket at offset 0');
    }

    public function testDisabledLetterDoesNotJump(): void
    {
        $screen = $this->loadedWithIndex();

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z')); // Z has no items

        self::assertSame(0, $next->grid()->cursorIndex());
        self::assertNull($cmd);
    }

    public function testLetterRailRendersInTheView(): void
    {
        // The rail dims disabled letters (faint SGR). A screen whose index hasn't
        // loaded shows no rail, so no such styling. (Exact bytes aren't asserted —
        // sugar-boxer re-flows the content box's ANSI.)
        $withRail = $this->loadedWithIndex()->view();
        $withoutRail = $this->loadedScreen(200)->view();

        self::assertStringContainsString("\033[2m", $withRail, 'the A–Z rail dims its empty letters');
        self::assertStringNotContainsString("\033[2m", $withoutRail);
    }

    public function testNoRailBeforeTheIndexLoads(): void
    {
        $loaded = $this->loadedScreen(200); // range only, no letter index fed

        self::assertNull($loaded->letterIndex());
    }

    // ---- filter mode ---------------------------------------------------

    public function testSlashEntersFilterMode(): void
    {
        [$filtering] = $this->loadedScreen(200)->update(new KeyMsg(KeyType::Char, '/'));

        self::assertTrue($filtering->isFiltering());
        self::assertStringContainsString('Search:', $filtering->view());
    }

    public function testSlashEntersFilterRatherThanJumping(): void
    {
        [$filtering] = $this->loadedWithIndex()->update(new KeyMsg(KeyType::Char, '/'));

        self::assertTrue($filtering->isFiltering());
        self::assertSame(0, $filtering->grid()->cursorIndex(), 'slash did not move the grid');
    }

    public function testSearchDebouncesThenAppliesOnTheMatchingSeq(): void
    {
        [$filtering] = $this->loadedScreen(200)->update(new KeyMsg(KeyType::Char, '/'));
        [$typed, $cmd] = $filtering->update(new KeyMsg(KeyType::Char, 'm'));

        self::assertSame('m', $typed->filterBar()->search);
        self::assertNull($typed->query()->search, 'search is not applied until the debounce fires');
        self::assertInstanceOf(\Closure::class, $cmd, 'a debounce tick is scheduled');

        [$applied, $fetch] = $typed->update(new SearchDebouncedMsg(1)); // seq 1 after one keystroke
        self::assertSame('m', $applied->query()->search);
        self::assertFalse($applied->isLoaded(), 'the grid is reset for the new query');
        self::assertInstanceOf(\Closure::class, $fetch);

        [, $none] = $applied->update(new SearchDebouncedMsg(0)); // stale
        self::assertNull($none);
    }

    public function testSortChangeAppliesImmediately(): void
    {
        [$filtering] = $this->loadedScreen(200)->update(new KeyMsg(KeyType::Char, '/'));
        [$onSort] = $filtering->update(new KeyMsg(KeyType::Tab));        // focus Sort
        [$sorted, $cmd] = $onSort->update(new KeyMsg(KeyType::Right));   // name → year

        self::assertSame('year', $sorted->query()->sort);
        self::assertFalse($sorted->isLoaded(), 'grid reset for the new sort');
        self::assertInstanceOf(\Closure::class, $cmd);
    }

    public function testFilterChangeBumpsGenerationDroppingStaleRanges(): void
    {
        [$filtering] = $this->loadedScreen(200)->update(new KeyMsg(KeyType::Char, '/'));
        [$onSort] = $filtering->update(new KeyMsg(KeyType::Tab));
        [$sorted] = $onSort->update(new KeyMsg(KeyType::Right)); // applyFilters → generation 1

        // A late range from the pre-filter query (generation 0) must be dropped.
        [$next] = $sorted->update(new MediaRangeLoadedMsg(new MediaRange([], 999), 0));

        self::assertFalse($next->isLoaded());
        self::assertSame(0, $next->grid()->total());
    }

    public function testEscExitsFilterModeKeepingTheFilter(): void
    {
        [$filtering] = $this->loadedScreen(200)->update(new KeyMsg(KeyType::Char, '/'));
        [$typed] = $filtering->update(new KeyMsg(KeyType::Char, 'm'));
        [$exited] = $typed->update(new KeyMsg(KeyType::Escape));

        self::assertFalse($exited->isFiltering());
        self::assertSame('m', $exited->filterBar()->search, 'the typed filter is retained');
        self::assertStringContainsString('(filtered)', $exited->view());
    }

    public function testTabCyclesControlsWithoutChangingTheQuery(): void
    {
        [$filtering] = $this->loadedScreen(200)->update(new KeyMsg(KeyType::Char, '/'));

        [$next, $cmd] = $filtering->update(new KeyMsg(KeyType::Tab));

        self::assertSame(1, $next->filterBar()->active, 'Tab moved focus to Sort');
        self::assertNull($cmd, 'moving focus issues no fetch');
    }

    public function testFailureBeforeLoadShowsError(): void
    {
        [$next] = $this->screenWith(new FakeTransport())->update(new LibraryFailedMsg('Could not load this library.'));

        self::assertStringContainsString('Could not load this library.', $next->view());
    }

    public function testTransientFailureAfterLoadIsIgnored(): void
    {
        $loaded = $this->loadedScreen(200);

        [$next] = $loaded->update(new LibraryFailedMsg('blip'));

        self::assertNull($next->error(), 'a scroll-time error does not blow away a populated grid');
        self::assertStringContainsString('200 items', $next->view());
    }

    public function testTransientFailureAfterLoadSurfacesAToast(): void
    {
        $loaded = $this->loadedScreen(200);

        [$next, $cmd] = $loaded->update(new LibraryFailedMsg('blip'));

        $toast = $this->runCmd($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast, 'the previously-silent error is now surfaced');
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('load more', $toast->message);
        // ... and the populated grid is left intact.
        self::assertNull($next->error());
        self::assertStringContainsString('200 items', $next->view());
    }

    public function testInitAuthFailureProducesSessionExpired(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $msgs = $this->runBatch($this->screenWith($transport)->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msgs[0]);
    }

    public function testResizeReflowsTheGridAndKeepsRendering(): void
    {
        $loaded = $this->loadedScreen(200);

        [$resized] = $loaded->update(new WindowSizeMsg(80, 24));

        self::assertInstanceOf(LibraryScreen::class, $resized);
        self::assertIsString($resized->view());
        // Narrower viewport → fewer columns.
        self::assertLessThan($loaded->grid()->columns(), $resized->grid()->columns());
    }

    public function testWidenResizeFetchesTheNewlyRevealedWindow(): void
    {
        $loaded = $this->loadedScreen(200); // 120×40

        [$resized, $cmd] = $loaded->update(new WindowSizeMsg(200, 60));

        self::assertGreaterThan($loaded->grid()->columns(), $resized->grid()->columns(), 'wider viewport → more columns');
        self::assertInstanceOf(\Closure::class, $cmd, 'a grown viewport must fetch the cells it newly exposes');
    }

    public function testGridPosterLoadedAttachesPoster(): void
    {
        $loaded = $this->loadedScreen(200);
        self::assertFalse($loaded->grid()->item(0)?->hasPoster());

        [$next] = $loaded->update(new GridPosterLoadedMsg(0, "▀▀▀\n▄▄▄"));

        self::assertTrue($next->grid()->item(0)?->hasPoster());
    }

    public function testGridPosterForUnknownCellIsIgnored(): void
    {
        $loaded = $this->loadedScreen(200);

        [$next] = $loaded->update(new GridPosterLoadedMsg(9999, 'X'));

        self::assertSame($loaded->grid()->total(), $next->grid()->total());
    }

    public function testPosterCmdRendersAndProducesGridPosterLoaded(): void
    {
        $port = $this->startPosterServer();
        $transport = (new FakeTransport())->json(200, [
            'items' => [['id' => '0', 'name' => 'M', 'type' => 'movie', 'poster_url' => "http://127.0.0.1:{$port}/p.png"]],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ]);
        $screen = $this->screenWith($transport, new PosterLoader(Mosaic::halfBlock()));

        $range = $this->runBatch($screen->init())[0];
        [$loaded, $cmd] = $screen->update($range);

        self::assertInstanceOf(\Closure::class, $cmd, 'posters are loaded for the visible window');
        $posterMsgs = array_filter($this->runBatch($cmd), static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertNotEmpty($posterMsgs);
        $msg = array_values($posterMsgs)[0];
        self::assertInstanceOf(GridPosterLoadedMsg::class, $msg);
        self::assertSame(0, $msg->index);
        self::assertNotSame('', $msg->ansi);
    }

    // ---- async Cmd runners (shared shape with BrowseScreenTest) ---------

    /**
     * Run a Cmd and collect the Msg(s) it yields. Invokes the closure exactly
     * once: a batch fans out to its child Cmds; a single async/sync Cmd resolves
     * from the result we already have (re-invoking would re-run the fetch).
     *
     * @return list<Msg>
     */
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

    private function runCmd(\Closure $cmd): ?Msg
    {
        $result = $cmd();
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

    private function startPosterServer(): int
    {
        $img = imagecreatetruecolor(8, 12);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 70, 120, 180));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        $server = new HttpServer(static fn (ServerRequestInterface $r): Response => new Response(200, ['Content-Type' => 'image/png'], $png));
        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);

        return (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }
}

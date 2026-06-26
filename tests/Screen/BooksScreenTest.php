<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\BooksFailedMsg;
use Phlix\Console\Msg\BooksRangeLoadedMsg;
use Phlix\Console\Msg\GridPosterLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenBookMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\BooksScreen;
use Phlix\Console\Store\BooksStore;
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
use SugarCraft\Toast\ToastType;

final class BooksScreenTest extends TestCase
{
    private ?SocketServer $socket = null;

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    private function screenWith(FakeTransport $transport, int $total = 200, ?PosterLoader $posters = null): BooksScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new BooksScreen(
            new BooksStore($api),
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            'https://srv',
            'lib-books',
            'Library',
            $total,
            cols: 120,
            rows: 40,
        );
    }

    /** A `/books` page whose book ids/titles equal their absolute index. */
    private function booksPage(int $offset, int $count, int $limit = 50): array
    {
        $books = [];
        for ($i = 0; $i < $count; $i++) {
            $abs = $offset + $i;
            $books[] = ['id' => (string) $abs, 'name' => "b{$abs}.epub", 'path' => "/x/b{$abs}.epub", 'metadata' => ['title' => "Book {$abs}"]];
        }

        return ['books' => $books, 'limit' => $limit, 'offset' => $offset];
    }

    /** A `/books/{id}` detail envelope with an optional signed cover URL. */
    private function bookDetail(string $id, ?string $coverUrl): array
    {
        return ['book' => [
            'id' => $id,
            'name' => "b{$id}.epub",
            'type' => 'book',
            'path' => "/x/b{$id}.epub",
            'metadata' => ['title' => "Book {$id}", 'author' => 'Some Author'],
            'cover_url' => $coverUrl,
            'download_url' => "/api/v1/books/{$id}/download?sig=x",
            'read_url' => "/api/v1/books/{$id}/read?sig=y",
        ]];
    }

    /** Build a screen whose initial window has loaded $total books (range only, no covers run). */
    private function loadedScreen(int $total = 200): BooksScreen
    {
        $transport = (new FakeTransport())->json(200, $this->booksPage(0, 50, 50));
        $screen = $this->screenWith($transport, $total);
        $range = $this->runBatch($screen->init())[0];
        self::assertInstanceOf(BooksRangeLoadedMsg::class, $range);

        // Feed the range; the returned cover Cmd is ignored here (those covers
        // never resolve — the transport has no detail responses queued).
        return $screen->update($range)[0];
    }

    public function testInitResetsToTheTotalAndFetchesTheFirstWindow(): void
    {
        $transport = (new FakeTransport())->json(200, $this->booksPage(0, 50, 50));
        $screen = $this->screenWith($transport, 200);

        // Even before the window resolves, the grid total is the passed item count.
        self::assertSame(200, $screen->grid()->total());

        $msgs = $this->runBatch($screen->init());
        self::assertInstanceOf(BooksRangeLoadedMsg::class, $msgs[0]);
        self::assertArrayHasKey(0, $msgs[0]->books);
        self::assertArrayNotHasKey(49, $msgs[0]->books, 'clipped to the visible window, not the whole page');
        self::assertStringContainsString('/api/v1/books?', $transport->requestAt(0)['url']);
        self::assertStringContainsString('library_id=lib-books', $transport->requestAt(0)['url']);
    }

    public function testLoadingViewBeforeData(): void
    {
        $view = $this->screenWith(new FakeTransport(), 200)->view();

        self::assertStringContainsString('Loading', $view);
        self::assertStringContainsString('Library', $view);
    }

    public function testLoadingBodyShowsTheShimmerSkeleton(): void
    {
        // The shimmer band's mid glyph (▒) is unique to the Skeleton (the grid's
        // own poster placeholders use ░/▓ only); at a mid-sweep phase it is on
        // screen, proving the loading body is the animated skeleton.
        $view = $this->screenWith(new FakeTransport(), 200)->withShimmerPhase(5)->view();

        self::assertStringContainsString('▒', $view, 'the loading body renders the shimmer band');
    }

    public function testTheSkeletonIsGoneOnceLoaded(): void
    {
        $view = $this->loadedScreen(200)->withShimmerPhase(5)->view();

        self::assertStringNotContainsString('▒', $view, 'the shimmer band disappears when the grid is loaded');
        self::assertStringContainsString('200 books', $view);
    }

    public function testIsLoadingIsTrueBeforeLoadAndFalseAfter(): void
    {
        $screen = $this->screenWith(new FakeTransport(), 200);
        self::assertTrue($screen->isLoading(), 'a fresh screen is loading');

        self::assertFalse($this->loadedScreen(200)->isLoading(), 'a populated screen is not loading');
    }

    public function testWithShimmerPhaseAdvancesTheLoadingBody(): void
    {
        $screen = $this->screenWith(new FakeTransport(), 200);

        $atZero = $screen->withShimmerPhase(0)->view();
        $atFive = $screen->withShimmerPhase(5)->view();

        self::assertNotSame($atZero, $atFive, 'the loading skeleton reflects the shimmer phase');
    }

    public function testRangeLoadedPopulatesTheGrid(): void
    {
        $loaded = $this->loadedScreen(200);

        self::assertTrue($loaded->isLoaded());
        self::assertSame(200, $loaded->grid()->total(), 'the total stays the passed item count');
        self::assertSame('0', $loaded->grid()->item(0)?->id);
        self::assertSame('Book 0', $loaded->grid()->item(0)?->title);
        self::assertStringContainsString('200 books', $loaded->view());
    }

    public function testEmptyLibraryRenders(): void
    {
        $transport = (new FakeTransport())->json(200, ['books' => [], 'limit' => 50, 'offset' => 0]);
        $screen = $this->screenWith($transport, 0);
        $range = $this->runBatch($screen->init())[0];
        [$loaded] = $screen->update($range);

        self::assertStringContainsString('No books', $loaded->view());
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

    public function testScrollingToANewWindowIssuesAFetch(): void
    {
        $loaded = $this->loadedScreen(200);

        [$end, $cmd] = $loaded->update(new KeyMsg(KeyType::End));
        self::assertSame(199, $end->grid()->cursorIndex());
        self::assertInstanceOf(\Closure::class, $cmd, 'the bottom window (a new range) must be fetched');
    }

    public function testEnterOpensTheFocusedBook(): void
    {
        [, $cmd] = $this->loadedScreen(200)->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenBookMsg::class, $msg);
        self::assertSame('0', $msg->id);
        self::assertSame('Book 0', $msg->title);
    }

    public function testEnterOnAnUnloadedCellIsANoOp(): void
    {
        $screen = $this->screenWith((new FakeTransport())->pending(), 200);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd, 'no detail opens for a not-yet-loaded cell');
    }

    public function testEscEmitsNavigateBack(): void
    {
        [, $cmd] = $this->loadedScreen(200)->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    public function testSupersededRangeResultIsDropped(): void
    {
        $loaded = $this->loadedScreen(200);
        $stale = new BooksRangeLoadedMsg([], 999);

        [$same] = $loaded->update($stale);

        self::assertSame(200, $same->grid()->total(), 'a result from a superseded generation is ignored');
    }

    public function testInitAuthFailureProducesSessionExpired(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $msgs = $this->runBatch($this->screenWith($transport, 200)->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msgs[0]);
    }

    public function testFailureBeforeLoadShowsError(): void
    {
        [$next] = $this->screenWith(new FakeTransport(), 200)->update(new BooksFailedMsg('Could not load this library.'));

        self::assertStringContainsString('Could not load this library.', $next->view());
        self::assertStringContainsString('Could not load this library.', (string) $next->error());
    }

    public function testTransientFailureAfterLoadKeepsTheGridAndSurfacesAToast(): void
    {
        $loaded = $this->loadedScreen(200);

        [$next, $cmd] = $loaded->update(new BooksFailedMsg('blip'));

        $toast = $this->runCmd($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('load more', $toast->message);
        self::assertNull($next->error(), 'a scroll-time error does not blow away the populated grid');
        self::assertStringContainsString('200 books', $next->view());
    }

    public function testResizeReflowsTheGridAndKeepsRendering(): void
    {
        $loaded = $this->loadedScreen(200);

        [$resized] = $loaded->update(new WindowSizeMsg(80, 24));

        self::assertInstanceOf(BooksScreen::class, $resized);
        self::assertIsString($resized->view());
        self::assertLessThan($loaded->grid()->columns(), $resized->grid()->columns(), 'narrower viewport → fewer columns');
    }

    public function testGridPosterLoadedAttachesCover(): void
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

    public function testUnhandledKeyAndMessageAreNoOps(): void
    {
        $loaded = $this->loadedScreen(200);

        [$afterKey, $keyCmd] = $loaded->update(new KeyMsg(KeyType::Tab));
        self::assertSame($loaded, $afterKey);
        self::assertNull($keyCmd);

        [$afterMsg, $msgCmd] = $loaded->update(new SessionExpiredMsg('ignored here'));
        self::assertSame($loaded, $afterMsg);
        self::assertNull($msgCmd);
    }

    public function testBreadcrumbLabelIsTheLibraryName(): void
    {
        self::assertSame('Library', $this->loadedScreen(200)->crumbLabel());

        $view = $this->loadedScreen(200)->withCrumbs(['Home', 'Library'])->view();
        self::assertStringContainsString('Library', $view);
    }

    // ---- lazy covers ---------------------------------------------------

    public function testLazyCoverFetchesTheBookDetailThenLoadsItsCover(): void
    {
        // A small library (1 book): init fetches the page, the range Cmd then
        // kicks off a per-cell cover load that resolves the book DETAIL (for the
        // signed cover_url) and renders it to ANSI → a GridPosterLoadedMsg.
        $port = $this->startCoverServer();
        $transport = (new FakeTransport())
            ->json(200, $this->booksPage(0, 1, 50))
            ->json(200, $this->bookDetail('0', "http://127.0.0.1:{$port}/cover.png"));
        $screen = $this->screenWith($transport, 1, new PosterLoader(Mosaic::halfBlock()));

        $range = $this->runBatch($screen->init())[0];
        self::assertInstanceOf(BooksRangeLoadedMsg::class, $range);

        [$loaded, $coverCmd] = $screen->update($range);
        self::assertInstanceOf(\Closure::class, $coverCmd, 'a loaded book without a cover triggers a lazy cover load');
        self::assertFalse($loaded->grid()->item(0)?->hasPoster());

        $posterMsgs = array_filter($this->runBatch($coverCmd), static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertNotEmpty($posterMsgs, 'the cover resolved to a GridPosterLoadedMsg');
        $msg = array_values($posterMsgs)[0];
        self::assertInstanceOf(GridPosterLoadedMsg::class, $msg);
        self::assertSame(0, $msg->index);
        self::assertNotSame('', $msg->ansi);

        [$withCover] = $loaded->update($msg);
        self::assertTrue($withCover->grid()->item(0)?->hasPoster());
    }

    public function testLazyCoverResolvesARelativeCoverUrlAgainstTheBase(): void
    {
        // The signed cover URL is a relative path; it is resolved against the
        // server base before rendering (here the base IS the test cover server).
        $port = $this->startCoverServer();
        $base = "http://127.0.0.1:{$port}";
        $api = new ApiClient($base, (new FakeTransport())
            ->json(200, $this->booksPage(0, 1, 50))
            ->json(200, $this->bookDetail('0', '/cover.png')));
        $screen = new BooksScreen(new BooksStore($api), new PosterLoader(Mosaic::halfBlock()), $base, 'lib-books', 'Library', 1, cols: 120, rows: 40);

        $range = $this->runBatch($screen->init())[0];
        [, $coverCmd] = $screen->update($range);

        $posterMsgs = array_filter($this->runBatch($coverCmd), static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertNotEmpty($posterMsgs, 'a relative cover URL resolved against the base and rendered');
    }

    public function testNameAccessorReturnsTheLibraryName(): void
    {
        self::assertSame('Library', $this->loadedScreen(200)->name());
    }

    public function testLazyCoverWithANullCoverUrlYieldsNoPoster(): void
    {
        // The book detail carries no signed cover_url → the lazy load resolves to
        // nothing (no GridPosterLoadedMsg) and the cell keeps its placeholder.
        $transport = (new FakeTransport())
            ->json(200, $this->booksPage(0, 1, 50))
            ->json(200, $this->bookDetail('0', null));
        $screen = $this->screenWith($transport, 1);

        $range = $this->runBatch($screen->init())[0];
        [$loaded, $coverCmd] = $screen->update($range);

        $msgs = $this->runBatch($coverCmd);
        $posterMsgs = array_filter($msgs, static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertEmpty($posterMsgs, 'a null cover URL produces no poster message');
        self::assertFalse($loaded->grid()->item(0)?->hasPoster(), 'the cell keeps its placeholder');
    }

    public function testLazyCoverWithANullCoverUrlSettlesTheChainToANullMsg(): void
    {
        // Pins the C4 `return resolve(null)` branch: a detail row whose cover_url
        // is null makes the FIRST then() return resolve(null) (a thenable), so the
        // SECOND then() receives $ansi === null and yields no message — the whole
        // per-cell cover Cmd settles to null (not a GridPosterLoadedMsg, not an
        // unhandled rejection). Asserting the settled value (null) directly proves
        // the resolve(null) thenable propagated, not merely that a batch was empty.
        $transport = (new FakeTransport())
            ->json(200, $this->booksPage(0, 1, 50))
            ->json(200, $this->bookDetail('0', null));
        $screen = $this->screenWith($transport, 1);

        $range = $this->runBatch($screen->init())[0];
        [, $coverCmd] = $screen->update($range);
        self::assertInstanceOf(\Closure::class, $coverCmd, 'a cover-less cell still triggers a lazy cover load');

        // The cover Cmd is a Cmd::batch over the single visible cell; unwrap it to
        // the lone per-cell cover promise and await it: the resolve(null) branch
        // makes the chain settle to a null Msg.
        $batch = $coverCmd();
        self::assertInstanceOf(BatchMsg::class, $batch);
        self::assertCount(1, $batch->cmds, 'exactly one cover load for the single book');
        self::assertNull($this->runCmd($batch->cmds[0]), 'the null-cover chain settles to a null Msg, keeping the placeholder');
    }

    public function testLazyCoverFailureIsSwallowed(): void
    {
        // The book-detail fetch itself fails → the cover load is best-effort and
        // swallows the error (no message, no crash, the cell keeps its skeleton).
        $transport = (new FakeTransport())
            ->json(200, $this->booksPage(0, 1, 50))
            ->fail(new \RuntimeException('boom'));
        $screen = $this->screenWith($transport, 1);

        $range = $this->runBatch($screen->init())[0];
        [$loaded, $coverCmd] = $screen->update($range);

        $msgs = $this->runBatch($coverCmd);
        self::assertSame([], array_filter($msgs, static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg), 'a failed cover yields no poster');
        self::assertFalse($loaded->grid()->item(0)?->hasPoster());
    }

    // ---- async Cmd runners (mirror LibraryScreenTest) ------------------

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

    private function startCoverServer(): int
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

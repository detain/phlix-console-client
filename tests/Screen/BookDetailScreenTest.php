<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\BookDetailPosterLoadedMsg;
use Phlix\Console\Msg\BookFailedMsg;
use Phlix\Console\Msg\BookLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Screen\BookDetailScreen;
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

final class BookDetailScreenTest extends TestCase
{
    private ?SocketServer $socket = null;

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    private function screenWith(FakeTransport $transport, ?PosterLoader $posters = null): BookDetailScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new BookDetailScreen(
            new BooksStore($api),
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            'https://srv',
            'b1',
            'Dune',
            cols: 120,
            rows: 40,
        );
    }

    /** The `{ "book": { … } }` detail envelope with sensible defaults. */
    private function detailResponse(array $overrides = []): array
    {
        return ['book' => array_merge([
            'id' => 'b1',
            'name' => 'dune.epub',
            'type' => 'book',
            'path' => '/library/dune.epub',
            'metadata' => ['title' => 'Dune', 'author' => 'Frank Herbert'],
            'cover_url' => null,
            'download_url' => '/api/v1/books/b1/download?sig=dl',
            'read_url' => '/api/v1/books/b1/read?sig=rd',
        ], $overrides)];
    }

    /** Load a book into the screen (init → BookLoadedMsg → update). */
    private function loaded(array $overrides = []): BookDetailScreen
    {
        $transport = (new FakeTransport())->json(200, $this->detailResponse($overrides));
        $screen = $this->screenWith($transport);
        $msg = $this->runBatch($screen->init())[0];
        self::assertInstanceOf(BookLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    public function testInitFetchesTheCorrectBookEndpoint(): void
    {
        $transport = (new FakeTransport())->json(200, $this->detailResponse());
        $screen = $this->screenWith($transport);

        $msg = $this->runBatch($screen->init())[0];

        self::assertInstanceOf(BookLoadedMsg::class, $msg);
        self::assertSame('b1', $msg->book->id);
        self::assertStringEndsWith('/api/v1/books/b1', $transport->requestAt(0)['url']);
    }

    public function testLoadedRendersTitleAuthorFormatAndDownloadUrl(): void
    {
        $loaded = $this->loaded();

        self::assertTrue($loaded->isLoaded());
        self::assertSame('b1', $loaded->book()?->id);

        $view = $loaded->view();
        self::assertStringContainsString('Dune', $view);
        self::assertStringContainsString('by Frank Herbert', $view);
        self::assertStringContainsString('EPUB', $view, 'the format is shown uppercased');
        self::assertStringContainsString('browser or e-reader', $view, 'the download hint is shown');
        self::assertStringContainsString('/api/v1/books/b1/download?sig=dl', $view, 'the signed download URL is copyable text');
    }

    public function testDownloadUrlIsResolvedAgainstTheBaseWhenRelative(): void
    {
        // A relative signed path is joined onto the server base for copy/paste.
        $view = $this->loaded(['download_url' => '/api/v1/books/b1/download?sig=rel'])->view();

        self::assertStringContainsString('https://srv/api/v1/books/b1/download?sig=rel', $view);
    }

    public function testAbsoluteDownloadUrlIsShownVerbatim(): void
    {
        $view = $this->loaded(['download_url' => 'https://cdn.example/dl?sig=abs'])->view();

        self::assertStringContainsString('https://cdn.example/dl?sig=abs', $view);
    }

    public function testALongSignedDownloadUrlWrapsInsteadOfTruncating(): void
    {
        // Regression: a real signed URL (~100+ chars) must not be hard-cut to one
        // column line — it is the only way to fetch the book. It now wraps across
        // lines, so even the tail (the signature) survives in the rendered view.
        $long = 'https://srv/api/v1/books/b1/download?expires=1719100000'
            . '&signature=abcdef0123456789abcdef0123456789deadbeefcafe';
        $view = $this->loaded(['download_url' => $long])->view();
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $view);

        self::assertStringContainsString('books/b1/download?expires', $plain, 'the URL head is shown');
        self::assertStringContainsString('deadbeefcafe', $plain, 'the URL tail survives (wrapped, not truncated)');
    }

    public function testFormatUppercasedFromTheExtension(): void
    {
        $view = $this->loaded(['path' => '/library/book.pdf'])->view();

        self::assertStringContainsString('PDF', $view);
    }

    public function testMissingDownloadShowsANotice(): void
    {
        $view = $this->loaded(['download_url' => null])->view();

        self::assertStringContainsString('No download is available', $view);
    }

    public function testNullCoverRendersThePlaceholderAndSkipsTheFetch(): void
    {
        $transport = (new FakeTransport())->json(200, $this->detailResponse(['cover_url' => null]));
        $screen = $this->screenWith($transport);
        $msg = $this->runBatch($screen->init())[0];

        [$loaded, $cmd] = $screen->update($msg);

        self::assertFalse($loaded->hasHero());
        self::assertNull($cmd, 'no cover → no hero fetch Cmd');
        self::assertIsString($loaded->view(), 'the placeholder still renders');
    }

    public function testLoadingViewBeforeTheBookArrives(): void
    {
        $view = $this->screenWith((new FakeTransport())->pending())->view();

        self::assertStringContainsString('Loading', $view);
        self::assertStringContainsString('Dune', $view, 'the seed title fills the header during load');
    }

    public function testFetchFailureShowsAnError(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('boom'));
        $screen = $this->screenWith($transport);

        $msg = $this->runBatch($screen->init())[0];
        self::assertInstanceOf(BookFailedMsg::class, $msg);

        [$failed] = $screen->update($msg);
        self::assertStringContainsString('Could not load', $failed->view());
        self::assertStringContainsString('Could not load', (string) $failed->error());
    }

    public function testAuthErrorBecomesSessionExpired(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'unauthorized']);
        $screen = $this->screenWith($transport);

        $msg = $this->runBatch($screen->init())[0];

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testEscapeNavigatesBack(): void
    {
        [, $cmd] = $this->screenWith((new FakeTransport())->pending())->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    public function testNonEscapeKeysAreNoOps(): void
    {
        $loaded = $this->loaded();

        // p / Enter do nothing special on a book (there is no reader).
        [$afterP, $pCmd] = $loaded->update(new KeyMsg(KeyType::Char, 'p'));
        self::assertSame($loaded, $afterP);
        self::assertNull($pCmd);

        [$afterEnter, $enterCmd] = $loaded->update(new KeyMsg(KeyType::Enter));
        self::assertSame($loaded, $afterEnter);
        self::assertNull($enterCmd);
    }

    public function testUnhandledMessageIsANoOp(): void
    {
        $loaded = $this->loaded();

        [$same, $cmd] = $loaded->update(new SessionExpiredMsg('ignored here'));

        self::assertSame($loaded, $same);
        self::assertNull($cmd);
    }

    public function testCoverFetchPopulatesTheHero(): void
    {
        $port = $this->startCoverServer();
        $transport = (new FakeTransport())->json(200, $this->detailResponse(['cover_url' => "http://127.0.0.1:{$port}/cover.png"]));
        $screen = $this->screenWith($transport);

        $loadMsg = $this->runBatch($screen->init())[0];
        [$loaded, $heroCmd] = $screen->update($loadMsg);
        self::assertInstanceOf(\Closure::class, $heroCmd, 'a cover URL kicks off a hero fetch');

        $posterMsg = $this->runCmd($heroCmd);
        self::assertInstanceOf(BookDetailPosterLoadedMsg::class, $posterMsg);

        [$withHero] = $loaded->update($posterMsg);
        self::assertTrue($withHero->hasHero());
    }

    public function testBrokenCoverKeepsThePlaceholder(): void
    {
        // The detail carries a cover URL, but the image fetch fails → the hero
        // fetch swallows the error (no message) and the placeholder remains.
        $transport = (new FakeTransport())->json(200, $this->detailResponse(['cover_url' => 'http://127.0.0.1:9/none.png']));
        $screen = $this->screenWith($transport);

        $loadMsg = $this->runBatch($screen->init())[0];
        [$loaded, $heroCmd] = $screen->update($loadMsg);

        $msgs = $this->runBatch($heroCmd);
        self::assertSame([], array_filter($msgs, static fn (Msg $m): bool => $m instanceof BookDetailPosterLoadedMsg), 'a broken cover yields no poster message');
        self::assertFalse($loaded->hasHero());
    }

    public function testResizeStillRenders(): void
    {
        [$next] = $this->loaded()->update(new WindowSizeMsg(60, 20));

        self::assertIsString($next->view());
    }

    public function testBreadcrumbLabelIsTheBookTitle(): void
    {
        self::assertSame('Dune', $this->loaded()->crumbLabel());

        $view = $this->loaded()->withCrumbs(['Home', 'Library', 'Dune'])->view();
        self::assertStringContainsString('Dune', $view);
        self::assertStringContainsString('›', $view);
    }

    public function testBreadcrumbFallsBackToTheSeedTitleBeforeLoad(): void
    {
        // Before the detail resolves the crumb is the seed title passed in.
        $screen = $this->screenWith((new FakeTransport())->pending());

        self::assertSame('Dune', $screen->crumbLabel());
    }

    // ---- async Cmd runners (mirror DetailScreenTest) -------------------

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

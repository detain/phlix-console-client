<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\GridPosterLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenPhotoAlbumMsg;
use Phlix\Console\Msg\PhotoAlbumsLoadedMsg;
use Phlix\Console\Msg\PhotosFailedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Screen\PhotosScreen;
use Phlix\Console\Store\PhotosStore;
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

final class PhotosScreenTest extends TestCase
{
    private ?SocketServer $socket = null;

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    private function screenWith(FakeTransport $transport, ?PosterLoader $posters = null, string $base = 'https://srv'): PhotosScreen
    {
        $api = new ApiClient($base, $transport);

        return new PhotosScreen(
            new PhotosStore($api),
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            $base,
            'lib-photos',
            'Photos',
            cols: 120,
            rows: 40,
        );
    }

    /**
     * A `/photo/albums` envelope. Each album's cover thumbnail (and its photos'
     * thumbnails) default to $thumb; pass null for a coverless album.
     */
    private function albumsEnvelope(int $count, ?string $thumb = 'https://srv/t.png'): array
    {
        $albums = [];
        for ($i = 0; $i < $count; $i++) {
            $albums[] = $this->album("a{$i}", sprintf('2026-06-%02d', $count - $i), $thumb);
        }

        return ['albums' => $albums];
    }

    private function album(string $id, string $date, ?string $thumb): array
    {
        $photo = [
            'id' => "{$id}-p0",
            'name' => "{$id}.jpg",
            'thumbnail_url' => $thumb,
            'full_url' => $thumb,
        ];

        return [
            'id' => $id,
            'date' => $date,
            'photo_count' => 3,
            'cover_photo' => $thumb === null ? null : $photo,
            'photos' => [$photo],
        ];
    }

    /** Build a screen whose albums have loaded (covers never resolve here — no cover server). */
    private function loadedScreen(int $count = 30): PhotosScreen
    {
        $transport = (new FakeTransport())->json(200, $this->albumsEnvelope($count, null));
        $screen = $this->screenWith($transport);
        $msgs = $this->runBatch($screen->init());
        self::assertInstanceOf(PhotoAlbumsLoadedMsg::class, $msgs[0]);

        // Feed the albums; the returned cover Cmd is ignored (coverless albums →
        // no covers to load).
        return $screen->update($msgs[0])[0];
    }

    public function testInitFetchesAlbumsWithTheLibraryIdAndBuildsTheGrid(): void
    {
        $transport = (new FakeTransport())->json(200, $this->albumsEnvelope(5, null));
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());
        self::assertInstanceOf(PhotoAlbumsLoadedMsg::class, $msgs[0]);
        self::assertCount(5, $msgs[0]->albums);
        self::assertStringContainsString('/api/v1/photo/albums?', $transport->requestAt(0)['url']);
        self::assertStringContainsString('library_id=lib-photos', $transport->requestAt(0)['url']);

        [$loaded] = $screen->update($msgs[0]);
        self::assertTrue($loaded->isLoaded());
        self::assertSame(5, $loaded->grid()->total(), 'the grid total is the album count');
    }

    public function testLoadingViewBeforeData(): void
    {
        $view = $this->screenWith(new FakeTransport())->view();

        self::assertStringContainsString('Loading', $view);
        self::assertStringContainsString('Photos', $view);
    }

    public function testAlbumsLoadedPopulatesTheGrid(): void
    {
        $loaded = $this->loadedScreen(30);

        self::assertTrue($loaded->isLoaded());
        self::assertSame(30, $loaded->grid()->total());
        self::assertSame('a0', $loaded->grid()->item(0)?->id);
        self::assertStringContainsString('30 albums', $loaded->view());
        self::assertCount(30, $loaded->albums());
    }

    public function testTheCardTitleCarriesTheDateAndPhotoCount(): void
    {
        $transport = (new FakeTransport())->json(200, ['albums' => [$this->album('a0', '2026-06-23', null)]]);
        $screen = $this->screenWith($transport);
        $msgs = $this->runBatch($screen->init());
        [$loaded] = $screen->update($msgs[0]);

        self::assertSame('2026-06-23 (3)', $loaded->grid()->item(0)?->title);
    }

    public function testEmptyLibraryRendersNoPhotoAlbums(): void
    {
        $transport = (new FakeTransport())->json(200, ['albums' => []]);
        $screen = $this->screenWith($transport);
        $msgs = $this->runBatch($screen->init());
        [$loaded] = $screen->update($msgs[0]);

        self::assertStringContainsString('No photo albums', $loaded->view());
    }

    public function testNavigationMovesTheCursor(): void
    {
        $loaded = $this->loadedScreen(30);

        [$right] = $loaded->update(new KeyMsg(KeyType::Right));
        self::assertSame(1, $right->grid()->cursorIndex());

        [$down] = $right->update(new KeyMsg(KeyType::Down));
        self::assertSame(1 + $loaded->grid()->columns(), $down->grid()->cursorIndex());

        [$end] = $down->update(new KeyMsg(KeyType::End));
        self::assertSame(29, $end->grid()->cursorIndex());

        [$home] = $end->update(new KeyMsg(KeyType::Home));
        self::assertSame(0, $home->grid()->cursorIndex());
    }

    public function testNavigationLoadsNewlyVisibleCovers(): void
    {
        // A grid taller than one screen: paging to the bottom must load the covers
        // for the cells now on screen (the thumbnails are known upfront).
        $port = $this->startCoverServer();
        $transport = (new FakeTransport())->json(200, $this->albumsEnvelope(200, "http://127.0.0.1:{$port}/cover.png"));
        $screen = $this->screenWith($transport, new PosterLoader(Mosaic::halfBlock()));
        $msgs = $this->runBatch($screen->init());
        [$loaded] = $screen->update($msgs[0]);

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::End));
        self::assertInstanceOf(\Closure::class, $cmd, 'paging to a new window loads the now-visible covers');

        $posterMsgs = array_filter($this->runBatch($cmd), static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertNotEmpty($posterMsgs, 'the newly-visible covers resolved');
    }

    public function testEnterOpensTheFocusedAlbum(): void
    {
        $loaded = $this->loadedScreen(30);

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenPhotoAlbumMsg::class, $msg);
        self::assertInstanceOf(PhotoAlbum::class, $msg->album);
        self::assertSame('a0', $msg->album->id, 'the cursor album is carried');
    }

    public function testEnterCarriesTheCursorAlbumAfterMoving(): void
    {
        $loaded = $this->loadedScreen(30);
        [$right] = $loaded->update(new KeyMsg(KeyType::Right));

        [, $cmd] = $right->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenPhotoAlbumMsg::class, $msg);
        self::assertSame('a1', $msg->album->id);
    }

    public function testEnterOnAnEmptyLibraryIsANoOp(): void
    {
        $transport = (new FakeTransport())->json(200, ['albums' => []]);
        $screen = $this->screenWith($transport);
        $msgs = $this->runBatch($screen->init());
        [$loaded] = $screen->update($msgs[0]);

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd, 'no album opens when the library is empty');
    }

    public function testEnterBeforeAlbumsLoadIsANoOp(): void
    {
        $screen = $this->screenWith((new FakeTransport())->pending());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd, 'no album opens before the albums have loaded');
    }

    public function testEscEmitsNavigateBack(): void
    {
        [, $cmd] = $this->loadedScreen(30)->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    public function testInitAuthFailureProducesSessionExpired(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $msgs = $this->runBatch($this->screenWith($transport)->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msgs[0]);
    }

    public function testInitFailureProducesPhotosFailed(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('boom'));
        $msgs = $this->runBatch($this->screenWith($transport)->init());

        self::assertInstanceOf(PhotosFailedMsg::class, $msgs[0]);
    }

    public function testPhotosFailedShowsTheErrorBody(): void
    {
        [$next] = $this->screenWith(new FakeTransport())->update(new PhotosFailedMsg('Could not load this library.'));

        self::assertStringContainsString('Could not load this library.', $next->view());
        self::assertStringContainsString('Could not load this library.', (string) $next->error());
    }

    public function testResizeReflowsTheGridAndKeepsRendering(): void
    {
        $loaded = $this->loadedScreen(30);

        [$resized] = $loaded->update(new WindowSizeMsg(80, 24));

        self::assertInstanceOf(PhotosScreen::class, $resized);
        self::assertIsString($resized->view());
        self::assertLessThan($loaded->grid()->columns(), $resized->grid()->columns(), 'narrower viewport → fewer columns');
    }

    public function testGridPosterLoadedAttachesCover(): void
    {
        $loaded = $this->loadedScreen(30);
        self::assertFalse($loaded->grid()->item(0)?->hasPoster());

        [$next] = $loaded->update(new GridPosterLoadedMsg(0, "▀▀▀\n▄▄▄"));

        self::assertTrue($next->grid()->item(0)?->hasPoster());
    }

    public function testGridPosterForUnknownCellIsIgnored(): void
    {
        $loaded = $this->loadedScreen(30);

        [$next] = $loaded->update(new GridPosterLoadedMsg(9999, 'X'));

        self::assertSame($loaded->grid()->total(), $next->grid()->total());
    }

    public function testUnhandledKeyAndMessageAreNoOps(): void
    {
        $loaded = $this->loadedScreen(30);

        [$afterKey, $keyCmd] = $loaded->update(new KeyMsg(KeyType::Tab));
        self::assertSame($loaded, $afterKey);
        self::assertNull($keyCmd);

        [$afterMsg, $msgCmd] = $loaded->update(new SessionExpiredMsg('ignored here'));
        self::assertSame($loaded, $afterMsg);
        self::assertNull($msgCmd);
    }

    public function testBreadcrumbLabelIsTheLibraryName(): void
    {
        self::assertSame('Photos', $this->loadedScreen(30)->crumbLabel());

        $view = $this->loadedScreen(30)->withCrumbs(['Home', 'Photos'])->view();
        self::assertStringContainsString('Photos', $view);
    }

    public function testNameAccessorReturnsTheLibraryName(): void
    {
        self::assertSame('Photos', $this->loadedScreen(30)->name());
    }

    // ---- direct covers (thumbnail known upfront) -----------------------

    public function testAlbumCoverLoadsFromTheSignedThumbnailDirectly(): void
    {
        // One album whose cover thumbnail is the test cover server: the albums
        // load → the returned cover Cmd renders the thumbnail to ANSI directly
        // (no detail fetch) → a GridPosterLoadedMsg attaches it to the cell.
        $port = $this->startCoverServer();
        $transport = (new FakeTransport())
            ->json(200, ['albums' => [$this->album('a0', '2026-06-23', "http://127.0.0.1:{$port}/cover.png")]]);
        $screen = $this->screenWith($transport, new PosterLoader(Mosaic::halfBlock()));

        $msgs = $this->runBatch($screen->init());
        [$loaded, $coverCmd] = $screen->update($msgs[0]);
        self::assertInstanceOf(\Closure::class, $coverCmd, 'a visible album with a cover triggers a cover load');
        self::assertFalse($loaded->grid()->item(0)?->hasPoster());

        $posterMsgs = array_filter($this->runBatch($coverCmd), static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertNotEmpty($posterMsgs, 'the cover resolved to a GridPosterLoadedMsg');
        $poster = array_values($posterMsgs)[0];
        self::assertInstanceOf(GridPosterLoadedMsg::class, $poster);
        self::assertSame(0, $poster->index);
        self::assertNotSame('', $poster->ansi);

        [$withCover] = $loaded->update($poster);
        self::assertTrue($withCover->grid()->item(0)?->hasPoster());
    }

    public function testAlbumCoverResolvesARelativeThumbnailAgainstTheBase(): void
    {
        // The signed thumbnail is a relative path; it is resolved against the base
        // (here the base IS the test cover server) before rendering.
        $port = $this->startCoverServer();
        $base = "http://127.0.0.1:{$port}";
        $transport = (new FakeTransport())->json(200, ['albums' => [$this->album('a0', '2026-06-23', '/cover.png')]]);
        $screen = $this->screenWith($transport, new PosterLoader(Mosaic::halfBlock()), $base);

        $msgs = $this->runBatch($screen->init());
        [, $coverCmd] = $screen->update($msgs[0]);

        $posterMsgs = array_filter($this->runBatch($coverCmd), static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertNotEmpty($posterMsgs, 'a relative thumbnail resolved against the base and rendered');
    }

    public function testAlbumWithNoCoverYieldsNoPoster(): void
    {
        // An album with a null cover thumbnail → no cover load (the card is
        // skipped) and the cell keeps its placeholder.
        $transport = (new FakeTransport())->json(200, ['albums' => [$this->album('a0', '2026-06-23', null)]]);
        $screen = $this->screenWith($transport);

        $msgs = $this->runBatch($screen->init());
        [$loaded, $coverCmd] = $screen->update($msgs[0]);

        self::assertNull($coverCmd, 'a coverless album triggers no cover load');
        self::assertFalse($loaded->grid()->item(0)?->hasPoster(), 'the cell keeps its placeholder');
    }

    public function testBrokenCoverIsSwallowed(): void
    {
        // The thumbnail points at a path the cover server 404s → the render fails
        // and is swallowed best-effort (no poster, no crash, placeholder kept).
        $port = $this->startCoverServer();
        $transport = (new FakeTransport())
            ->json(200, ['albums' => [$this->album('a0', '2026-06-23', "http://127.0.0.1:{$port}/nope.png")]]);
        $screen = $this->screenWith($transport, new PosterLoader(Mosaic::halfBlock()));

        $msgs = $this->runBatch($screen->init());
        [$loaded, $coverCmd] = $screen->update($msgs[0]);

        $posterMsgs = array_filter($this->runBatch($coverCmd), static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertEmpty($posterMsgs, 'a broken cover yields no poster');
        self::assertFalse($loaded->grid()->item(0)?->hasPoster());
    }

    // ---- async Cmd runners (mirror BooksScreenTest) --------------------

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

        $server = new HttpServer(static function (ServerRequestInterface $r) use ($png): Response {
            // A 404 path lets a "broken cover" test exercise the best-effort swallow.
            return str_contains((string) $r->getUri()->getPath(), 'nope')
                ? new Response(404, [], 'not found')
                : new Response(200, ['Content-Type' => 'image/png'], $png);
        });
        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);

        return (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }
}

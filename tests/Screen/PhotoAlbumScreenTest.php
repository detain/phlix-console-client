<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\GridPosterLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\PhotoAlbumScreen;
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

final class PhotoAlbumScreenTest extends TestCase
{
    private ?SocketServer $socket = null;

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    private function album(int $photoCount, ?string $thumb = 'https://srv/t.png', string $date = '2026-06-23'): PhotoAlbum
    {
        $photos = [];
        for ($i = 0; $i < $photoCount; $i++) {
            $photos[] = [
                'id' => "p{$i}",
                'name' => "p{$i}.jpg",
                'thumbnail_url' => $thumb,
                'full_url' => $thumb,
            ];
        }

        return PhotoAlbum::fromArray([
            'id' => 'a0',
            'date' => $date,
            'photo_count' => $photoCount,
            'photos' => $photos,
        ]);
    }

    private function screen(PhotoAlbum $album, ?PosterLoader $posters = null, string $base = 'https://srv'): PhotoAlbumScreen
    {
        return new PhotoAlbumScreen(
            $album,
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            $base,
            cols: 120,
            rows: 40,
        );
    }

    public function testCtorBuildsTheGridFromTheAlbumPhotos(): void
    {
        $screen = $this->screen($this->album(12, null));

        self::assertSame(12, $screen->grid()->total(), 'the grid total is the photo count');
        self::assertSame('p0', $screen->grid()->item(0)?->id);
        self::assertSame('p0.jpg', $screen->grid()->item(0)?->title);
    }

    public function testInitLoadsTheVisibleThumbnailsDirectly(): void
    {
        // The album carries signed thumbnails → init renders the visible ones
        // directly (no data fetch) → GridPosterLoadedMsgs.
        $port = $this->startCoverServer();
        $screen = $this->screen($this->album(3, "http://127.0.0.1:{$port}/cover.png"), new PosterLoader(Mosaic::halfBlock()));

        $cmd = $screen->init();
        self::assertInstanceOf(\Closure::class, $cmd, 'init loads the visible thumbnails');

        $posterMsgs = array_filter($this->runBatch($cmd), static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertNotEmpty($posterMsgs, 'the visible thumbnails resolved');
        $poster = array_values($posterMsgs)[0];
        self::assertInstanceOf(GridPosterLoadedMsg::class, $poster);

        [$withThumb] = $screen->update($poster);
        self::assertTrue($withThumb->grid()->item($poster->index)?->hasPoster());
    }

    public function testInitResolvesARelativeThumbnailAgainstTheBase(): void
    {
        $port = $this->startCoverServer();
        $base = "http://127.0.0.1:{$port}";
        $screen = $this->screen($this->album(1, '/cover.png'), new PosterLoader(Mosaic::halfBlock()), $base);

        $posterMsgs = array_filter($this->runBatch($screen->init()), static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertNotEmpty($posterMsgs, 'a relative thumbnail resolved against the base and rendered');
    }

    public function testInitWithNoThumbnailsLoadsNothing(): void
    {
        // Photos with null thumbnails → no thumbnail load, cells keep placeholders.
        $screen = $this->screen($this->album(3, null));

        self::assertNull($screen->init(), 'no thumbnails to load');
        self::assertFalse($screen->grid()->item(0)?->hasPoster());
    }

    public function testBrokenThumbnailIsSwallowed(): void
    {
        $port = $this->startCoverServer();
        $screen = $this->screen($this->album(1, "http://127.0.0.1:{$port}/nope.png"), new PosterLoader(Mosaic::halfBlock()));

        $posterMsgs = array_filter($this->runBatch($screen->init()), static fn (Msg $m): bool => $m instanceof GridPosterLoadedMsg);
        self::assertEmpty($posterMsgs, 'a broken thumbnail yields no poster');
        self::assertFalse($screen->grid()->item(0)?->hasPoster());
    }

    public function testRendersThePhotoCountHeader(): void
    {
        $view = $this->screen($this->album(12, null))->view();

        self::assertStringContainsString('12 photos', $view);
        self::assertStringContainsString('2026-06-23', $view, 'the album date titles the frame');
    }

    public function testEmptyAlbumRendersNoPhotos(): void
    {
        $view = $this->screen($this->album(0))->view();

        self::assertStringContainsString('No photos', $view);
    }

    public function testNavigationMovesTheCursor(): void
    {
        $screen = $this->screen($this->album(30, null));

        [$right] = $screen->update(new KeyMsg(KeyType::Right));
        self::assertSame(1, $right->grid()->cursorIndex());

        [$end] = $right->update(new KeyMsg(KeyType::End));
        self::assertSame(29, $end->grid()->cursorIndex());

        [$home] = $end->update(new KeyMsg(KeyType::Home));
        self::assertSame(0, $home->grid()->cursorIndex());
    }

    public function testEnterSurfacesTheViewerSoonToastWhenPhotosPresent(): void
    {
        $screen = $this->screen($this->album(12, null));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(ShowToastMsg::class, $msg);
        self::assertSame(ToastType::Info, $msg->type);
        self::assertStringContainsString('next update', $msg->message);
    }

    public function testEnterOnAnEmptyAlbumIsANoOp(): void
    {
        $screen = $this->screen($this->album(0));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd, 'no viewer toast when the album is empty');
    }

    public function testEscEmitsNavigateBack(): void
    {
        [, $cmd] = $this->screen($this->album(12, null))->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    public function testResizeReflowsTheGridAndKeepsRendering(): void
    {
        $screen = $this->screen($this->album(30, null));

        [$resized] = $screen->update(new WindowSizeMsg(80, 24));

        self::assertInstanceOf(PhotoAlbumScreen::class, $resized);
        self::assertIsString($resized->view());
        self::assertLessThan($screen->grid()->columns(), $resized->grid()->columns(), 'narrower viewport → fewer columns');
    }

    public function testGridPosterLoadedAttachesThumbnail(): void
    {
        $screen = $this->screen($this->album(12, null));
        self::assertFalse($screen->grid()->item(0)?->hasPoster());

        [$next] = $screen->update(new GridPosterLoadedMsg(0, "▀▀▀\n▄▄▄"));

        self::assertTrue($next->grid()->item(0)?->hasPoster());
    }

    public function testGridPosterForUnknownCellIsIgnored(): void
    {
        $screen = $this->screen($this->album(12, null));

        [$next] = $screen->update(new GridPosterLoadedMsg(9999, 'X'));

        self::assertSame($screen->grid()->total(), $next->grid()->total());
    }

    public function testUnhandledKeyAndMessageAreNoOps(): void
    {
        $screen = $this->screen($this->album(12, null));

        [$afterKey, $keyCmd] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertSame($screen, $afterKey);
        self::assertNull($keyCmd);

        [$afterMsg, $msgCmd] = $screen->update(new SessionExpiredMsg('ignored here'));
        self::assertSame($screen, $afterMsg);
        self::assertNull($msgCmd);
    }

    public function testBreadcrumbLabelIsTheAlbumDate(): void
    {
        self::assertSame('2026-06-23', $this->screen($this->album(12, null))->crumbLabel());

        $view = $this->screen($this->album(12, null))->withCrumbs(['Home', 'Photos', '2026-06-23'])->view();
        self::assertStringContainsString('2026-06-23', $view);
    }

    public function testAlbumAccessorReturnsTheAlbum(): void
    {
        $album = $this->album(12, null);

        self::assertSame($album, $this->screen($album)->album());
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
            return str_contains((string) $r->getUri()->getPath(), 'nope')
                ? new Response(404, [], 'not found')
                : new Response(200, ['Content-Type' => 'image/png'], $png);
        });
        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);

        return (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }
}

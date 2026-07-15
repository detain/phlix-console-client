<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Media;

use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Media\PosterLoadResult;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use SugarCraft\Mosaic\DiskCache;
use SugarCraft\Mosaic\Mosaic;

/**
 * End-to-end poster pipeline against an ephemeral 127.0.0.1 server serving a
 * GD-generated PNG: fetch → half-block render → disk cache.
 */
final class PosterLoaderTest extends \PHPUnit\Framework\TestCase
{
    private ?SocketServer $socket = null;
    private int $port = 0;
    private int $served = 0;
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/phlix-posters-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
        parent::tearDown();
    }

    public function testLoadFetchesRendersAndCaches(): void
    {
        $this->startServer();
        $cache = new DiskCache($this->cacheDir);
        $loader = new PosterLoader(Mosaic::halfBlock(), $cache);
        $url = "http://127.0.0.1:{$this->port}/poster.png";

        $result = $this->await($loader->load($url, 8, 12));

        self::assertInstanceOf(PosterLoadResult::class, $result);
        self::assertIsString($result->marker);
        self::assertNotSame('', $result->marker);
        self::assertNull($result->imageId);
        self::assertSame(1, $this->served);
        self::assertSame(1, $cache->count(), 'rendered ANSI was cached to disk');
    }

    public function testSecondLoadServesFromCacheWithoutRefetching(): void
    {
        $this->startServer();
        $cache = new DiskCache($this->cacheDir);
        $loader = new PosterLoader(Mosaic::halfBlock(), $cache);
        $url = "http://127.0.0.1:{$this->port}/poster.png";

        $first = $this->await($loader->load($url, 8, 12));
        $second = $this->await($loader->load($url, 8, 12));

        self::assertSame($first->marker, $second->marker);
        self::assertSame(1, $this->served, 'cache hit avoided a second HTTP fetch');
    }

    public function testWithoutCacheStillRenders(): void
    {
        $this->startServer();
        $loader = new PosterLoader(Mosaic::halfBlock());

        $ansi = $this->await($loader->load("http://127.0.0.1:{$this->port}/poster.png", 6, 9));

        self::assertNotSame('', $ansi);
    }

    public function testInlineModeLeavesTheImageLayerEmpty(): void
    {
        $this->startServer();
        $loader = new PosterLoader(Mosaic::halfBlock(), null);

        $this->await($loader->load("http://127.0.0.1:{$this->port}/poster.png", 6, 9));

        self::assertSame([], $loader->imageLayer(), 'cell renderers stay inline');
    }

    public function testOverlayModeReturnsAMarkerBlockAndRegistersTheBlob(): void
    {
        $this->startServer();
        $loader = new PosterLoader(Mosaic::sixel(), null);

        $result = $this->await($loader->load("http://127.0.0.1:{$this->port}/poster.png", 6, 9));

        self::assertInstanceOf(PosterLoadResult::class, $result);
        self::assertIsString($result->marker);
        // A 9-row marker block, top-left = marker(0), and NO raw sixel in the text.
        self::assertCount(9, explode("\n", $result->marker));
        self::assertStringContainsString(\SugarCraft\Core\ImageOverlay::marker(0), $result->marker);
        self::assertStringNotContainsString("\x1bP", $result->marker, 'the sixel blob never enters the text frame');
        // The blob lives in the image layer instead (as an ImagePlacement).
        $layer = $loader->imageLayer();
        self::assertArrayHasKey(0, $layer);
        self::assertInstanceOf(\SugarCraft\Core\ImagePlacement::class, $layer[0]);
        self::assertStringContainsString("\x1bP", $layer[0]->bytes, 'registered bytes are the sixel blob');
        self::assertSame(6, $layer[0]->widthCells);
        self::assertSame(9, $layer[0]->heightCells);
    }

    public function testOverlayModeReusesTheIdForTheSamePosterAndSize(): void
    {
        $this->startServer();
        $loader = new PosterLoader(Mosaic::sixel(), null);
        $url = "http://127.0.0.1:{$this->port}/poster.png";

        $a = $this->await($loader->load($url, 6, 9));
        $b = $this->await($loader->load($url, 6, 9));

        self::assertSame($a->imageId, $b->imageId, 'same poster → same marker id → same block');
        self::assertCount(1, $loader->imageLayer(), 'one registered image, not two');
    }

    public function testLoadSkipsInvalidUrls(): void
    {
        $loader = new PosterLoader(Mosaic::halfBlock());

        // NEW correct behavior: throws \InvalidArgumentException for invalid URLs
        // (error handler catches it and returns null - no message created)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing URL scheme');
        $this->await($loader->load('', 8, 12));
    }

    public function testLoadRejectsRelativeUrls(): void
    {
        $loader = new PosterLoader(Mosaic::halfBlock());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing URL scheme');
        $this->await($loader->load('/images/poster.jpg', 8, 12));
    }

    public function testLoadRejectsFileSchemeUrls(): void
    {
        $loader = new PosterLoader(Mosaic::halfBlock());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing URL scheme');
        $this->await($loader->load('file:///images/poster.jpg', 8, 12));
    }

    private function startServer(): void
    {
        $png = $this->pngBytes();
        $server = new HttpServer(function (ServerRequestInterface $request) use ($png): Response {
            $this->served++;

            return new Response(200, ['Content-Type' => 'image/png'], $png);
        });
        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);
        $this->port = (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }

    private function pngBytes(): string
    {
        $img = imagecreatetruecolor(8, 12);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 90, 140, 200));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function await(PromiseInterface $promise, float $timeout = 5.0): mixed
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
        $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
        if (!$state['done']) {
            throw new \RuntimeException('poster load did not settle in time');
        }
        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}

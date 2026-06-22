<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Media;

use Phlix\Console\Media\PosterLoader;
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

        $ansi = $this->await($loader->load($url, 8, 12));

        self::assertIsString($ansi);
        self::assertNotSame('', $ansi);
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

        self::assertSame($first, $second);
        self::assertSame(1, $this->served, 'cache hit avoided a second HTTP fetch');
    }

    public function testWithoutCacheStillRenders(): void
    {
        $this->startServer();
        $loader = new PosterLoader(Mosaic::halfBlock());

        $ansi = $this->await($loader->load("http://127.0.0.1:{$this->port}/poster.png", 6, 9));

        self::assertNotSame('', $ansi);
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

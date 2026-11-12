<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Media;

use Phlix\Console\Media\PosterLoadResult;
use Phlix\Console\Media\PosterLoader;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;

/**
 * CU-055 [review console__secaudit m3]: the poster fetch must honour sugar-image's
 * SSRF deny-list on EVERY host that is not the configured server's own authority.
 *
 * The pre-cure shape allow-listed each URL's own host unconditionally, so the
 * vendor guard was a no-op for every fetch — a wire-named artwork host pointing
 * at a cloud-metadata endpoint (169.254.169.254) or an RFC1918 machine was
 * requested without judgement. These tests route through the real async path
 * (Mosaic::posterAsync → ImageSource::fromUrlAsync) with a deterministic host
 * resolver (the vendor's overrideHostResolver test seam) and pin the boundary:
 *
 *  - non-http(s) schemes reject SYNCHRONOUSLY at the loader boundary;
 *  - a non-server host resolving to a blocked address rejects with the vendor's
 *    "blocked private/reserved address" reason BEFORE any socket is opened
 *    (the old shape convicts here: it reached the connect, surfacing a DNS/
 *    connection error instead of the guard's reason);
 *  - the server-authority bypass does NOT travel with a redirect: a trusted
 *    initial host 302-ing into metadata is refused at the second hop;
 *  - the configured server's own loopback/LAN artwork still renders (positive
 *    control — the cure must not break self-hosted deployments);
 *  - with no server bound (empty base) nothing is exempted: fail closed.
 */
final class PosterLoaderSsrfTest extends \PHPUnit\Framework\TestCase
{
    private ?SocketServer $socket = null;
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $png = $this->pngBytes();
        $server = new HttpServer(function (ServerRequestInterface $request) use ($png): Response {
            if ($request->getUri()->getPath() === '/redirect-to-metadata') {
                return new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/iam/']);
            }

            return new Response(200, ['Content-Type' => 'image/png'], $png);
        });
        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);
        $this->port = (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }

    protected function tearDown(): void
    {
        ImageSource::overrideHostResolver(null);
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    public function testNonHttpSchemesRejectPromise(): void
    {
        $loader = $this->loader('http://phlix.example:8096');

        foreach (['file:///etc/passwd', 'gopher://internal.example:11211/_stats'] as $url) {
            $error = null;
            $loader->load($url, 8, 12)->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });
            self::assertInstanceOf(\InvalidArgumentException::class, $error);
            self::assertSame('Invalid or missing URL scheme', $error->getMessage());
        }
    }

    public function testWireNamedHostResolvingToMetadataIsRefused(): void
    {
        ImageSource::overrideHostResolver(static fn (string $host): array => ['169.254.169.254']);
        $loader = $this->loader('http://phlix.example:8096');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address \(169\.254\.169\.254\)#');
        $this->await($loader->load('http://evil.example/poster.png', 8, 12));
    }

    public function testWireNamedHostResolvingToRfc1918IsRefused(): void
    {
        ImageSource::overrideHostResolver(static fn (string $host): array => ['10.0.0.5']);
        $loader = $this->loader('http://phlix.example:8096');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address \(10\.0\.0\.5\)#');
        $this->await($loader->load('http://intranet.example/poster.png', 8, 12));
    }

    public function testServerAuthorityBypassDoesNotTravelWithRedirect(): void
    {
        $loader = $this->loader($this->serverUrl());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address \(169\.254\.169\.254\)#');
        $this->await($loader->load("{$this->serverUrl()}/redirect-to-metadata", 8, 12));
    }

    public function testServerHostedPosterStillRenders(): void
    {
        $loader = $this->loader($this->serverUrl());

        $result = $this->await($loader->load("{$this->serverUrl()}/poster.png", 8, 12));

        self::assertInstanceOf(PosterLoadResult::class, $result);
        self::assertNotSame('', $result->marker, 'the server authority grant keeps self-hosted loopback/LAN art working');
    }

    public function testUnboundServerRefusesEvenLoopbackArtwork(): void
    {
        $loader = $this->loader('');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#blocked private/reserved address#');
        $this->await($loader->load("{$this->serverUrl()}/poster.png", 8, 12));
    }

    private function serverUrl(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    private function loader(string $serverBaseUrl): PosterLoader
    {
        return new PosterLoader(
            Mosaic::halfBlock(),
            null,
            serverBaseUrl: static fn (): string => $serverBaseUrl,
        );
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

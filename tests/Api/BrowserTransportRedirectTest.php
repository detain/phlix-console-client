<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use Phlix\Console\Api\BrowserTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

/**
 * Per-hop bearer law [review CON-4]: Authorization survives a redirect only
 * to the exact origin (scheme, host AND port) of the original request.
 */
final class BrowserTransportRedirectTest extends TestCase
{
    /** @var list<SocketServer> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            $socket->close();
        }
        $this->sockets = [];
        parent::tearDown();
    }

    /** @return int port */
    private function serve(callable $handler): int
    {
        $socket = new SocketServer('127.0.0.1:0');
        (new HttpServer($handler))->listen($socket);
        $this->sockets[] = $socket;

        return (int) parse_url((string) $socket->getAddress(), PHP_URL_PORT);
    }

    /** @param PromiseInterface<ResponseInterface> $promise */
    private function await(PromiseInterface $promise): ResponseInterface
    {
        $value = null;
        $error = null;
        $promise->then(
            static function ($v) use (&$value): void {
                $value = $v;
                Loop::stop();
            },
            static function ($e) use (&$error): void {
                $error = $e;
                Loop::stop();
            },
        );
        $timer = Loop::addTimer(5.0, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
        if ($error instanceof \Throwable) {
            throw $error;
        }
        self::assertInstanceOf(ResponseInterface::class, $value);

        return $value;
    }

    public function testCrossPortRedirectNeverResendsAuthorization(): void
    {
        $seenB = [];
        $portB = $this->serve(static function (ServerRequestInterface $r) use (&$seenB): Response {
            $seenB[] = $r->getHeaderLine('Authorization');

            return new Response(200, [], 'ok');
        });
        $portA = $this->serve(
            static fn (): Response => new Response(302, ['Location' => "http://127.0.0.1:{$portB}/x"]),
        );

        $res = $this->await((new BrowserTransport())->send(
            'GET',
            "http://127.0.0.1:{$portA}/a",
            ['Authorization' => 'Bearer secret'],
            '',
        ));

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([''], $seenB, 'bearer must not follow a cross-origin hop');
    }

    public function testSameOriginRedirectKeepsAuthorization(): void
    {
        $seen = [];
        $port = $this->serve(static function (ServerRequestInterface $r) use (&$seen): Response {
            $seen[] = $r->getHeaderLine('Authorization');
            if ($r->getUri()->getPath() === '/a') {
                return new Response(302, ['Location' => '/b']);
            }

            return new Response(200, [], 'ok');
        });

        $this->await((new BrowserTransport())->send(
            'GET',
            "http://127.0.0.1:{$port}/a",
            ['Authorization' => 'Bearer secret'],
            '',
        ));

        self::assertSame(['Bearer secret', 'Bearer secret'], $seen);
    }

    public function testRaw3xxWithoutLocationIsReturnedAsIs(): void
    {
        $port = $this->serve(static fn (): Response => new Response(302));

        $res = $this->await((new BrowserTransport())->send('POST', "http://127.0.0.1:{$port}/auth/logout", [], ''));

        self::assertSame(302, $res->getStatusCode());
    }

    public function testResolveLocationAndOrigin(): void
    {
        self::assertSame('http://h:81/x', BrowserTransport::resolveLocation('http://h:81/a/b', '/x'));
        self::assertSame('http://h/a/c', BrowserTransport::resolveLocation('http://h/a/b', 'c'));
        self::assertNull(BrowserTransport::resolveLocation('http://h/a', 'file:///etc/passwd'));
        self::assertNotSame(BrowserTransport::originOf('https://h/'), BrowserTransport::originOf('http://h/'));
        self::assertSame(BrowserTransport::originOf('https://H/'), BrowserTransport::originOf('https://h:443/y'));
    }
}

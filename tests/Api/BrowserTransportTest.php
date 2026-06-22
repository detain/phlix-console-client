<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use Phlix\Console\Api\BrowserTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

/**
 * Exercises the real ReactPHP-backed transport against an ephemeral
 * 127.0.0.1 server — no external network, but the genuine HTTP path.
 */
final class BrowserTransportTest extends \PHPUnit\Framework\TestCase
{
    private ?SocketServer $socket = null;
    private int $port = 0;

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    public function testResolvesOnSuccess(): void
    {
        $this->startServer();
        $transport = new BrowserTransport();

        $response = $this->await($transport->send('GET', $this->url('/ok'), [], ''));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['hello' => 'world'], json_decode((string) $response->getBody(), true));
    }

    public function testResolvesRatherThanRejectsOnErrorStatus(): void
    {
        // The contract the ApiClient relies on: a 5xx must RESOLVE (so the
        // client can read the status), not reject the promise.
        $this->startServer();
        $transport = new BrowserTransport();

        $response = $this->await($transport->send('GET', $this->url('/boom'), [], ''));

        self::assertSame(500, $response->getStatusCode());
    }

    public function testForwardsMethodHeadersAndBody(): void
    {
        $seen = [];
        $this->startServer($seen);
        $transport = new BrowserTransport();

        $response = $this->await($transport->send(
            'POST',
            $this->url('/echo'),
            ['X-Token' => 'abc', 'Content-Type' => 'application/json'],
            '{"k":"v"}',
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('POST', $seen['method'] ?? null);
        self::assertSame('abc', $seen['x-token'] ?? null);
        self::assertSame('{"k":"v"}', $seen['body'] ?? null);
    }

    /** @param array<string,string> $seen */
    private function startServer(array &$seen = []): void
    {
        $server = new HttpServer(static function (ServerRequestInterface $request) use (&$seen): Response {
            $seen['method'] = $request->getMethod();
            $seen['body'] = (string) $request->getBody();
            foreach ($request->getHeaders() as $name => $values) {
                $seen[strtolower($name)] = implode(',', $values);
            }

            return match ($request->getUri()->getPath()) {
                '/ok'   => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['hello' => 'world'])),
                '/echo' => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['ok' => true])),
                default => new Response(500, ['Content-Type' => 'application/json'], (string) json_encode(['error' => 'boom'])),
            };
        });

        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);
        $this->port = (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }

    private function url(string $path): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    private function await(PromiseInterface $promise, float $timeout = 5.0): ResponseInterface
    {
        $settled = null;
        $error = null;
        $promise->then(
            function ($value) use (&$settled): void {
                $settled = $value;
                Loop::stop();
            },
            function ($reason) use (&$error): void {
                $error = $reason;
                Loop::stop();
            },
        );

        $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        if ($error !== null) {
            throw $error;
        }
        self::assertInstanceOf(ResponseInterface::class, $settled, 'transport did not settle in time');

        return $settled;
    }
}

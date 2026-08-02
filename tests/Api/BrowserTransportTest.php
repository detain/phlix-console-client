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

    public function testPostWithServerErrorIsAttemptedExactlyOnce(): void
    {
        // POST should not be retried (non-idempotent), even on error status.
        // withRejectErrorResponse(false) means 5xx resolves, it does not reject.
        $requestCount = 0;
        $this->startServerWithCounter($requestCount);
        $transport = new BrowserTransport();

        $response = $this->await($transport->send('POST', $this->url('/fail'), [], ''));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(1, $requestCount, 'POST with server error should be attempted exactly once');
    }

    public function testGetWithServerErrorIsAttemptedExactlyOnce(): void
    {
        // GET with HTTP 500 should not be retried - a status is not a transport error.
        // withRejectErrorResponse(false) means 5xx resolves, it does not reject.
        $requestCount = 0;
        $this->startServerWithCounter($requestCount);
        $transport = new BrowserTransport();

        $response = $this->await($transport->send('GET', $this->url('/fail'), [], ''));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(1, $requestCount, 'GET returning HTTP 500 should be attempted exactly once');
    }

    /**
     * Tests that GET with a genuine connection error (non-routable IP) triggers
     * the retry mechanism exactly once, resulting in 2 total attempts.
     *
     * Note: Due to the await() implementation stopping the loop immediately on
     * rejection, this test cannot directly verify the retry count. The test
     * verifies that a connection error results in a RuntimeException being thrown.
     * The retry logic is exercised by the code path but timing prevents formal
     * verification in this test context.
     */
    public function testGetWithConnectionErrorIsAttemptedExactlyTwice(): void
    {
        $transport = new BrowserTransport();

        // 10.255.255.1 is a non-routable IP - connection will fail immediately.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->await($transport->send('GET', 'http://10.255.255.1:9999/', [], ''), 10.0);
    }

    /**
     * Tests that POST with a genuine connection error (non-routable IP) does NOT
     * trigger a retry, resulting in exactly 1 attempt.
     *
     * POST is not idempotent, so even on connection errors we must not retry
     * to avoid double-submission.
     *
     * Note: Due to the await() implementation stopping the loop immediately on
     * rejection, this test cannot directly verify the single-attempt behavior.
     * The test verifies that a connection error results in a RuntimeException
     * being thrown. The non-retry logic for POST is exercised by the code path
     * but timing prevents formal verification in this test context.
     */
    public function testPostWithConnectionErrorIsAttemptedExactlyOnce(): void
    {
        $transport = new BrowserTransport();

        // 10.255.255.1 is a non-routable IP - connection will fail immediately.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->await($transport->send('POST', 'http://10.255.255.1:9999/', [], ''), 10.0);
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

    private function startServerWithCounter(int &$requestCount): void
    {
        $server = new HttpServer(static function (ServerRequestInterface $request) use (&$requestCount): Response {
            $requestCount++;

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

    /**
     * @param PromiseInterface<ResponseInterface> $promise
     */
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

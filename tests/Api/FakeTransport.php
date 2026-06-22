<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use Phlix\Console\Api\Transport;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * A scripted {@see Transport} for ApiClient tests: queue canned responses (or a
 * transport failure), then assert on the recorded requests. No real HTTP, no
 * live server — exactly what CI needs.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:string}> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $queue = [];

    private bool $pending = false;

    /**
     * @param array<string,mixed> $body
     */
    public function json(int $status, array $body): self
    {
        $this->queue[] = new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));

        return $this;
    }

    public function raw(int $status, string $body): self
    {
        $this->queue[] = new Response($status, [], $body);

        return $this;
    }

    /** Queue a transport-level failure (no HTTP response). */
    public function fail(\Throwable $error): self
    {
        $this->queue[] = $error;

        return $this;
    }

    /** Make every request hang unresolved (to observe in-flight behaviour). */
    public function pending(): self
    {
        $this->pending = true;

        return $this;
    }

    public function send(string $method, string $url, array $headers, string $body): PromiseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->pending) {
            return (new Deferred())->promise();
        }

        $next = array_shift($this->queue) ?? new Response(200, [], '{}');

        return $next instanceof \Throwable ? reject($next) : resolve($next);
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /** @return array{method:string,url:string,headers:array<string,string>,body:string}|null */
    public function lastRequest(): ?array
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    /** @return array{method:string,url:string,headers:array<string,string>,body:string} */
    public function requestAt(int $index): array
    {
        return $this->requests[$index];
    }
}

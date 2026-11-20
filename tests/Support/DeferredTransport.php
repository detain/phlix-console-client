<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Support;

use Phlix\Console\Api\Transport;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * A {@see Transport} whose responses settle only when a test says so. Every
 * request parks a Deferred, so a test can start a fetch, change the store under
 * it (invalidate, or a server switch) and then deliver the response that was
 * already in flight.
 */
final class DeferredTransport implements Transport
{
    /** @var list<array{method: string, url: string, deferred: Deferred}> */
    public array $requests = [];

    public function send(string $method, string $url, array $headers, string $body): PromiseInterface
    {
        $deferred = new Deferred();
        $this->requests[] = ['method' => $method, 'url' => $url, 'deferred' => $deferred];

        return $deferred->promise();
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * Settle the request at $index with a 200 JSON body.
     *
     * @param array<string, mixed> $body
     */
    public function respondJson(int $index, array $body): void
    {
        $this->requests[$index]['deferred']->resolve(
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body)),
        );
    }
}

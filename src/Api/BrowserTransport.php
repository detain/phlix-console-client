<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api;

use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Default {@see Transport} backed by ReactPHP's HTTP Browser.
 *
 * The Browser is configured with `withRejectErrorResponse(false)` so 4xx/5xx
 * responses resolve like any other (the ApiClient maps status → typed error);
 * only genuine transport failures reject.
 *
 * Timeout is set to 15 seconds and retry logic is applied for idempotent
 * GET/HEAD requests on connection/timeout errors.
 */
final class BrowserTransport implements Transport
{
    /**
     * @var array<string>
     */
    private const RETRYABLE_METHODS = ['GET', 'HEAD'];

    private const MAX_RETRIES = 1;

    private const RETRY_DELAY_SECONDS = 0.25;

    private readonly Browser $browser;

    /**
     * @param float $timeoutSeconds Request timeout in seconds, defaults to 15.0
     */
    public function __construct(?Browser $browser = null, float $timeoutSeconds = 15.0)
    {
        $this->browser = ($browser ?? new Browser())
            ->withRejectErrorResponse(false)
            ->withTimeout($timeoutSeconds);
    }

    /**
     * @param array<string,string> $headers
     * @return PromiseInterface<\Psr\Http\Message\ResponseInterface>
     */
    public function send(string $method, string $url, array $headers, string $body): PromiseInterface
    {
        if (!in_array($method, self::RETRYABLE_METHODS, true)) {
            return $this->browser->request($method, $url, $headers, $body);
        }

        return $this->retryableRequest($method, $url, $headers, $body, 0);
    }

    /**
     * @param array<string,string> $headers
     * @return PromiseInterface<\Psr\Http\Message\ResponseInterface>
     */
    private function retryableRequest(string $method, string $url, array $headers, string $body, int $attempt): PromiseInterface
    {
        /** @var PromiseInterface<\Psr\Http\Message\ResponseInterface> $requestPromise */
        $requestPromise = $this->browser->request($method, $url, $headers, $body);

        return $requestPromise->then(
            static fn ($response) => $response,
            function (\Throwable $reason) use ($method, $url, $headers, $body, $attempt): PromiseInterface {
                if ($attempt >= self::MAX_RETRIES) {
                    return \React\Promise\reject($reason);
                }

                return $this->delayAndRetry($method, $url, $headers, $body, $attempt);
            },
        );
    }

    /**
     * @param array<string,string> $headers
     * @return PromiseInterface<\Psr\Http\Message\ResponseInterface>
     */
    private function delayAndRetry(string $method, string $url, array $headers, string $body, int $attempt): PromiseInterface
    {
        /** @var Deferred<\React\Promise\PromiseInterface<\Psr\Http\Message\ResponseInterface>> $deferred */
        $deferred = new Deferred();

        $loop = Loop::get();
        $loop->addTimer(self::RETRY_DELAY_SECONDS, function () use ($deferred, $method, $url, $headers, $body, $attempt): void {
            $retryPromise = $this->retryableRequest($method, $url, $headers, $body, $attempt + 1);
            $deferred->resolve($retryPromise);
        });

        return $deferred->promise();
    }
}

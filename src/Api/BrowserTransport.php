<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api;

use React\Http\Browser;
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
     * HTTP methods considered idempotent and safe to retry on transport errors.
     *
     * @var array<string>
     */
    private const RETRYABLE_METHODS = ['GET', 'HEAD'];

    /**
     * Maximum number of retry attempts beyond the initial request.
     *
     * A value of 1 means one initial attempt plus one retry = up to 2 attempts total.
     */
    private const MAX_RETRIES = 1;

    /**
     * Seconds to wait before issuing a retry after a transport failure.
     *
     * @var float
     */
    private const RETRY_DELAY_SECONDS = 0.25;

    /**
     * The underlying ReactPHP HTTP Browser used to send requests.
     *
     * Configured with `withRejectErrorResponse(false)` so 4xx/5xx resolve
     * instead of rejecting — only genuine transport failures reject.
     *
     * @var Browser
     */
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
     * Issue an HTTP request that retries once on a transport failure.
     *
     * Wraps the Browser request in a promise that catches rejection (transport
     * errors only — not HTTP status codes, since withRejectErrorResponse(false)
     * is set) and reschedules the attempt after RETRY_DELAY_SECONDS if the
     * attempt count is below MAX_RETRIES.
     *
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
     * Wait RETRY_DELAY_SECONDS then call retryableRequest with attempt+1.
     *
     * @param array<string,string> $headers
     * @return PromiseInterface<\Psr\Http\Message\ResponseInterface>
     */
    private function delayAndRetry(string $method, string $url, array $headers, string $body, int $attempt): PromiseInterface
    {
        return \React\Promise\Timer\sleep(self::RETRY_DELAY_SECONDS)->then(
            function () use ($method, $url, $headers, $body, $attempt): PromiseInterface {
                return $this->retryableRequest($method, $url, $headers, $body, $attempt + 1);
            },
        );
    }
}

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

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

    /** Redirect hops followed before giving up. */
    private const MAX_REDIRECTS = 10;

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
            ->withFollowRedirects(false)
            ->withTimeout($timeoutSeconds);
    }

    /**
     * @param array<string,string> $headers
     * @return PromiseInterface<\Psr\Http\Message\ResponseInterface>
     */
    public function send(string $method, string $url, array $headers, string $body): PromiseInterface
    {
        return $this->followRedirects($method, $url, $headers, $body, self::originOf($url), 0);
    }

    /**
     * Follow 3xx hops by hand so the bearer is judged PER HOP [review CON-4].
     * The vendored Browser strips Authorization only when the HOST changes: a
     * same-host https-to-http downgrade or a port change would still carry the
     * bearer. Here Authorization survives a hop only when scheme, host and port
     * all equal the ORIGINAL request's origin; once dropped it never returns.
     * A 3xx without a Location is handed back raw (logoutAt tolerates it).
     *
     * @param array<string,string> $headers
     * @return PromiseInterface<\Psr\Http\Message\ResponseInterface>
     */
    private function followRedirects(
        string $method,
        string $url,
        array $headers,
        string $body,
        ?string $origin,
        int $hop,
    ): PromiseInterface {
        return $this->dispatch($method, $url, $headers, $body)->then(
            function ($response) use ($method, $url, $headers, $body, $origin, $hop) {
                $status = $response->getStatusCode();
                $location = $response->getHeaderLine('Location');
                if ($status < 300 || $status >= 400 || $location === '') {
                    return $response;
                }
                if ($hop >= self::MAX_REDIRECTS) {
                    throw new \RuntimeException('Maximum number of redirects (' . self::MAX_REDIRECTS . ') exceeded');
                }
                $next = self::resolveLocation($url, $location);
                if ($next === null) {
                    throw new \RuntimeException('Refusing redirect to a non-http(s) location');
                }
                if (self::originOf($next) !== $origin) {
                    foreach (array_keys($headers) as $name) {
                        if (strcasecmp((string) $name, 'Authorization') === 0) {
                            unset($headers[$name]);
                        }
                    }
                }
                if ($status !== 307 && $status !== 308) {
                    $method = $method === 'HEAD' ? 'HEAD' : 'GET';
                    $body = '';
                    foreach (array_keys($headers) as $name) {
                        if (in_array(strtolower((string) $name), ['content-type', 'content-length'], true)) {
                            unset($headers[$name]);
                        }
                    }
                }

                return $this->followRedirects($method, $next, $headers, $body, $origin, $hop + 1);
            },
        );
    }

    /** scheme://host:port of an absolute http(s) URL, or null. */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    /** Resolve a Location against the current URL; null when not http(s). */
    public static function resolveLocation(string $current, string $location): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return self::originOf($location) === null ? null : $location;
        }
        $parts = parse_url($current);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $authority = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $authority . $dir . $location;
    }

    /**
     * One hop, retrying idempotent methods once on transport failure.
     *
     * @param array<string,string> $headers
     * @return PromiseInterface<\Psr\Http\Message\ResponseInterface>
     */
    private function dispatch(string $method, string $url, array $headers, string $body): PromiseInterface
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

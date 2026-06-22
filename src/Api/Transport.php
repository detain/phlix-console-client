<?php

declare(strict_types=1);

namespace Phlix\Console\Api;

use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;

/**
 * The HTTP seam under {@see ApiClient}: send a request, resolve with the
 * response regardless of status code, and only reject on a transport failure
 * (DNS, connection refused, timeout — anything with no HTTP response).
 *
 * Keeping status interpretation in the ApiClient (not the transport) makes the
 * client mockable with a trivial fake and independent of any Browser config.
 */
interface Transport
{
    /**
     * @param array<string,string> $headers
     * @return PromiseInterface<ResponseInterface>
     */
    public function send(string $method, string $url, array $headers, string $body): PromiseInterface;
}

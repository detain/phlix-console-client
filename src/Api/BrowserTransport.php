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
 */
final class BrowserTransport implements Transport
{
    private readonly Browser $browser;

    public function __construct(?Browser $browser = null) {
        $this->browser = ($browser ?? new Browser())->withRejectErrorResponse(false);
    }

    public function send(string $method, string $url, array $headers, string $body): PromiseInterface
    {
        return $this->browser->request($method, $url, $headers, $body);
    }
}

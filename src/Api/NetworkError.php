<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Api;

/**
 * The request never produced an HTTP response — DNS failure, connection
 * refused, TLS error, or timeout. Distinct from {@see ApiError} (which carries
 * a real HTTP status) so the UI can say "can't reach the server" rather than
 * surfacing a status-code message.
 *
 * Also minted when a 2xx response carries a non-empty body that is not valid
 * JSON (captive portal, proxy error page, truncated stream): the server was
 * not usably reached, so the call fails closed with the same curated sentence
 * and statusCode 0; the "invalid JSON" diagnostic rides the previous chain
 * [review CON-6].
 */
final class NetworkError extends ApiError
{
}

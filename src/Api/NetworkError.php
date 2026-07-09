<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api;

/**
 * The request never produced an HTTP response — DNS failure, connection
 * refused, TLS error, or timeout. Distinct from {@see ApiError} (which carries
 * a real HTTP status) so the UI can say "can't reach the server" rather than
 * surfacing a status-code message.
 */
final class NetworkError extends ApiError
{
}

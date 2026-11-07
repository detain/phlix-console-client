<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Api;

/**
 * An API call failed. `statusCode` is the HTTP status (0 for transport
 * failures), and `body` is the decoded JSON error payload when present.
 */
class ApiError extends \RuntimeException
{
    /**
     * @param array<array-key,mixed>|null $body  Decoded error payload; integer
     *                                           keys when the server returned a
     *                                           bare JSON list (CU-045 law).
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?array $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

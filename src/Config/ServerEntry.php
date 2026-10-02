<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Config;

/**
 * A single server entry in the multi-server configuration.
 * Used as a value object; identity is the $id (UUID).
 */
final readonly class ServerEntry
{
    public function __construct(
        public string $id,
        public string $label,
        public string $url,
        public ?string $hubId = null,
        /**
         * Optional override for the server's SyncPlay WebSocket port. The
         * phlix-server WS worker listens on plaintext :8097 by default
         * (server `config/server.php` → `websocket.port`), independent of the
         * HTTP base in `$url`. Null means "use the default" — see
         * {@see \Phlix\Console\Api\SyncPlay\SyncPlayService::DEFAULT_WS_PORT}.
         */
        public ?int $wsPort = null,
    ) {
    }
}

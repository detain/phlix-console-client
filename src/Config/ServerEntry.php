<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
    ) {
    }
}

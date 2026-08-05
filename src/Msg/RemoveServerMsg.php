<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Remove a server from the configured list. */
final readonly class RemoveServerMsg implements Msg
{
    public function __construct(
        public string $serverId,
    ) {
    }
}

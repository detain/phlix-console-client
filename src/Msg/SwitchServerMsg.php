<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Switch to a different configured server. */
final readonly class SwitchServerMsg implements Msg
{
    public function __construct(
        public string $serverId,
    ) {
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Open the Servers screen — the App pushes a ServersScreen onto the stack. */
final readonly class OpenServersMsg implements Msg
{
}

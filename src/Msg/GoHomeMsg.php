<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Command-palette action: return to the browse home (pop the stack to its root).
 */
final readonly class GoHomeMsg implements Msg
{
}

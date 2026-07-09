<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Pop the top screen off the stack, revealing the one beneath (e.g. Library → Browse). */
final readonly class NavigateBackMsg implements Msg
{
}

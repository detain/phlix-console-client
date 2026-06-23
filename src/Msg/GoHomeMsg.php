<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Command-palette action: return to the browse home (pop the stack to its root).
 */
final readonly class GoHomeMsg implements Msg
{
}

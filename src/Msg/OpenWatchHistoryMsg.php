<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open the watch history screen ("Recently Watched").
 * The App pushes a WatchHistoryScreen onto the stack.
 */
final readonly class OpenWatchHistoryMsg implements Msg
{
}

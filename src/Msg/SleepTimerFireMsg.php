<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Fires when the sleep timer expires — signals the player to pause.
 */
final readonly class SleepTimerFireMsg implements Msg
{
}

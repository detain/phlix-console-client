<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Fires when the sleep timer expires — signals the player to pause.
 */
final readonly class SleepTimerFireMsg implements Msg
{
}

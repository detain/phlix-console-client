<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Fires every second while a sleep timer is active.
 * Carries the remaining seconds on the timer.
 */
final readonly class SleepTimerTickMsg implements Msg
{
    public function __construct(
        public int $remainingSeconds,
    ) {
    }
}

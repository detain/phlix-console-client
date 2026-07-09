<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The toast prune tick. The App schedules one of these for the soonest toast
 * expiry (via sugar-toast's {@see \SugarCraft\Toast\Toast::secondsUntilNextExpiry()});
 * on receipt it prunes expired alerts and reschedules while any remain.
 */
final readonly class ToastTickMsg implements Msg
{
}

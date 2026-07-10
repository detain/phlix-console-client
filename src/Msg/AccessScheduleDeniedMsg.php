<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Playback was denied because the current time is outside the allowed access schedule.
 * The user should return home and try again during allowed hours.
 */
final readonly class AccessScheduleDeniedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

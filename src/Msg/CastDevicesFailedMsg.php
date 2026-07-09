<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Device discovery failed — the {@see \Phlix\Console\Screen\CastScreen} shows the
 * error state with an `r` retry. (Discovery is per-backend fault-tolerant, so this
 * only fires on a total failure of the fan-out.)
 */
final readonly class CastDevicesFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

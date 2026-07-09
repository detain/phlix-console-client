<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Cast\CastDevice;
use SugarCraft\Core\Msg;

/**
 * The cast send succeeded — the {@see \Phlix\Console\Screen\CastScreen} enters
 * Transport mode bound to this device and arms the status poll.
 */
final readonly class CastStartedMsg implements Msg
{
    public function __construct(
        public CastDevice $device,
    ) {
    }
}

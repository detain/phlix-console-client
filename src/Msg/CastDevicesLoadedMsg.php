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
 * Device discovery resolved — the {@see \Phlix\Console\Screen\CastScreen} shows
 * the picker for these targets (an empty list renders the placeholder).
 */
final readonly class CastDevicesLoadedMsg implements Msg
{
    /**
     * @param list<CastDevice> $devices
     */
    public function __construct(
        public array $devices,
    ) {
    }
}

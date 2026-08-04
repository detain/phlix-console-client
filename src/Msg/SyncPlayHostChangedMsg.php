<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The SyncPlay room host has changed.
 */
final readonly class SyncPlayHostChangedMsg implements Msg
{
    public function __construct(
        public string $newHostId,
    ) {
    }
}

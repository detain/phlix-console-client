<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * SyncPlay room action failed (join, create, etc).
 */
final readonly class SyncPlayFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

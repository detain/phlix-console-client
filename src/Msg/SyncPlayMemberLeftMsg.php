<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A member left the SyncPlay room.
 */
final readonly class SyncPlayMemberLeftMsg implements Msg
{
    public function __construct(
        public string $memberId,
    ) {
    }
}

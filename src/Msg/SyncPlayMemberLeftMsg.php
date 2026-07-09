<?php

declare(strict_types=1);

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

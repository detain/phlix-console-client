<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\SyncPlayRoom;
use SugarCraft\Core\Msg;

/**
 * Joined a SyncPlay room successfully.
 */
final readonly class SyncPlayJoinedMsg implements Msg
{
    public function __construct(
        public SyncPlayRoom $room,
    ) {
    }
}

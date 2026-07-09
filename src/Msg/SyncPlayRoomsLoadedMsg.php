<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\SyncPlayRoom;
use SugarCraft\Core\Msg;

/**
 * SyncPlay rooms list loaded from the server.
 *
 * @param list<SyncPlayRoom> $rooms
 */
final readonly class SyncPlayRoomsLoadedMsg implements Msg
{
    /**
     * @param list<SyncPlayRoom> $rooms
     */
    public function __construct(
        public array $rooms,
    ) {
    }
}

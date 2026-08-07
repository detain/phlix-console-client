<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\SyncPlayGroup;
use SugarCraft\Core\Msg;

/**
 * SyncPlay rooms list loaded from the server.
 *
 * @param list<SyncPlayGroup> $rooms
 */
final readonly class SyncPlayGroupsLoadedMsg implements Msg
{
    /**
     * @param list<SyncPlayGroup> $rooms
     */
    public function __construct(
        public array $rooms,
    ) {
    }
}

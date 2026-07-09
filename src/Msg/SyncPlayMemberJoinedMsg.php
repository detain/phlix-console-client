<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\SyncPlayUser;
use SugarCraft\Core\Msg;

/**
 * A member joined the SyncPlay room.
 */
final readonly class SyncPlayMemberJoinedMsg implements Msg
{
    public function __construct(
        public SyncPlayUser $member,
    ) {
    }
}

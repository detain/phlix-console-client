<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\SyncPlayPlaybackCommand;
use SugarCraft\Core\Msg;

/**
 * A SyncPlay playback command received from the group host.
 */
final readonly class SyncPlayPlaybackCommandMsg implements Msg
{
    public function __construct(
        public SyncPlayPlaybackCommand $command,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Building the player failed (e.g. no local ffmpeg, or the probe errored). The
 * {@see \Phlix\Console\Screen\PlayerScreen} shows the reason instead of a frame.
 */
final readonly class PlayerPrepareFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

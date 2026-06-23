<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\TranscodeJob;
use SugarCraft\Core\Msg;

/**
 * A transcode job was started for an item that couldn't be direct-played. The
 * {@see \Phlix\Console\Screen\PlayerScreen} either plays its master playlist
 * (if already ready) or begins polling.
 */
final readonly class TranscodeStartedMsg implements Msg
{
    public function __construct(
        public TranscodeJob $job,
    ) {
    }
}

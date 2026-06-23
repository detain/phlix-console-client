<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\TranscodeJob;
use SugarCraft\Core\Msg;

/**
 * A transcode-status poll result: the {@see \Phlix\Console\Screen\PlayerScreen}
 * plays the master playlist once it's ready, shows an error on failure, or polls
 * again.
 */
final readonly class TranscodeStatusMsg implements Msg
{
    public function __construct(
        public TranscodeJob $job,
    ) {
    }
}

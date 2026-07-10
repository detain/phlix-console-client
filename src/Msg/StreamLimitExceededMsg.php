<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Playback was denied because the stream limit (concurrent streams) has been reached.
 * The user should stop another stream before continuing.
 */
final readonly class StreamLimitExceededMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

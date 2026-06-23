<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The throttled heartbeat that drives playback-progress reporting — distinct
 * from the sugar-reel frame tick. On each one the {@see
 * \Phlix\Console\Screen\PlayerScreen} POSTs the current position to its session
 * and re-arms the next.
 */
final readonly class ProgressTickMsg implements Msg
{
}

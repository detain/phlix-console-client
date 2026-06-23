<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The 1-second tick of the end-of-episode "up next" countdown. On each one the
 * {@see \Phlix\Console\Screen\PlayerScreen} decrements the counter and, at zero,
 * advances to the next episode.
 */
final readonly class UpNextTickMsg implements Msg
{
}

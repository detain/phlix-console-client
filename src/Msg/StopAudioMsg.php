<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Stop and clear the App's active music session — tears down the player and
 * removes the now-playing bar. Emitted by the command palette's Stop action.
 */
final readonly class StopAudioMsg implements Msg
{
}

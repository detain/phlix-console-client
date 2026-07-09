<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Reel\Player;

/**
 * The sugar-reel {@see Player} for the requested item has been built (the stream
 * probed, the decoder opened) and is ready to start. Delivered to the
 * {@see \Phlix\Console\Screen\PlayerScreen}, which begins playback.
 */
final readonly class PlayerReadyMsg implements Msg
{
    public function __construct(
        public Player $player,
    ) {
    }
}

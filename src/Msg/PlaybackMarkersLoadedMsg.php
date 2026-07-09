<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\PlaybackMarkers;
use SugarCraft\Core\Msg;

/**
 * The item's intro/outro skip markers + chapters arrived (from
 * `/media/{id}/playback-info`). The {@see \Phlix\Console\Screen\PlayerScreen}
 * uses them for the scrubber's chapter ticks and the contextual skip prompt.
 * Optional — a fetch failure simply leaves the player without ticks/skips.
 */
final readonly class PlaybackMarkersLoadedMsg implements Msg
{
    public function __construct(
        public PlaybackMarkers $markers,
    ) {
    }
}

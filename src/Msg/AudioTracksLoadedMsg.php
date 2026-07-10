<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\StreamAudioTrack;
use SugarCraft\Core\Msg;

/**
 * The item's available audio tracks arrived (from
 * `/media/{id}/playback`). The {@see \Phlix\Console\Screen\PlayerScreen}
 * uses them to populate the audio track picker menu.
 */
final readonly class AudioTracksLoadedMsg implements Msg
{
    /**
     * @param list<StreamAudioTrack> $audioTracks
     */
    public function __construct(
        public array $audioTracks,
    ) {
    }
}

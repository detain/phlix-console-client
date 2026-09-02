<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\StreamAudioTrack;
use Phlix\Console\Api\Dto\StreamSubtitleTrack;
use SugarCraft\Core\Msg;

/**
 * The item's available playback tracks arrived (from `/media/{id}/playback`).
 * The {@see \Phlix\Console\Screen\PlayerScreen} uses them to populate the
 * audio track picker menu and — since S413, mirroring the audio pattern — the
 * subtitle track picker menu.
 *
 * The class name keeps the historic `Audio` spelling: one `playbackInfo()`
 * round-trip carries BOTH track lists and resolves this single message; the
 * rename radius (screen, dispatch, tests) bought nothing over the defaulting
 * second field. `subtitleTracks` defaults to `[]` so every existing
 * construction site (audio-only payloads) stays valid.
 */
final readonly class AudioTracksLoadedMsg implements Msg
{
    /**
     * @param list<StreamAudioTrack>    $audioTracks
     * @param list<StreamSubtitleTrack> $subtitleTracks
     */
    public function __construct(
        public array $audioTracks,
        public array $subtitleTracks = [],
    ) {
    }
}

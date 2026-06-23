<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Album;
use SugarCraft\Core\Msg;

/**
 * Start playing a track from an album — the {@see \Phlix\Console\Screen\AlbumScreen}
 * emits this on Enter, and the App (which now OWNS the music audio session) resolves
 * the track's signed stream URL and spawns the player. Carries the whole {@see Album}
 * (so the App can auto-advance through it) and the track's index within it.
 */
final readonly class PlayTrackMsg implements Msg
{
    public function __construct(
        public Album $album,
        public int $index,
    ) {
    }
}

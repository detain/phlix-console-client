<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A track's signed stream URL resolved and is ready to play — the AlbumScreen
 * spawns the {@see \SugarCraft\Reel\AudioPlayer} for it. Carries the track's
 * index in the album (so the now-playing line + selection stay consistent) and
 * the absolute, server-resolved stream URL handed verbatim to ffplay/mpv.
 */
final readonly class AudioStartedMsg implements Msg
{
    public function __construct(
        public int $index,
        public string $url,
    ) {
    }
}

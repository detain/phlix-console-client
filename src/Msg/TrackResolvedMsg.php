<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Album;
use SugarCraft\Core\Msg;

/**
 * A track's signed stream URL resolved and is ready to play — the App spawns the
 * {@see \SugarCraft\Reel\AudioPlayer} for it and opens (or replaces) its active
 * {@see \Phlix\Console\Audio\NowPlaying} session. Carries the album + the track's
 * index (so the session knows what is playing and can auto-advance) and the
 * absolute, server-resolved stream URL handed verbatim to ffplay/mpv.
 */
final readonly class TrackResolvedMsg implements Msg
{
    public function __construct(
        public Album $album,
        public int $index,
        public string $url,
    ) {
    }
}

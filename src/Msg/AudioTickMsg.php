<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * One second elapsed during audio playback. The {@see \SugarCraft\Reel\AudioPlayer}
 * exposes no playhead, so the AlbumScreen estimates the elapsed position by
 * counting these 1-second ticks while a track plays (re-arming each tick) — and
 * auto-advances to the next track once the count reaches the track's duration.
 *
 * Carries the {@see \Phlix\Console\Screen\AlbumScreen} audio epoch it was armed
 * under: any state change that (re)starts the heartbeat (start, resume, n/p,
 * auto-advance) bumps the epoch, so a tick left over from a superseded chain is
 * recognised as stale and dropped — preventing two heartbeats from running at
 * once (which would double the estimated position and auto-advance early).
 */
final readonly class AudioTickMsg implements Msg
{
    public function __construct(public int $epoch = 0)
    {
    }
}

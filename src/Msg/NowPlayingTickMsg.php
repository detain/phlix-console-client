<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * One second elapsed during the App's music playback. The
 * {@see \SugarCraft\Reel\AudioPlayer} exposes no playhead, so the App estimates
 * the elapsed position of its active {@see \Phlix\Console\Audio\MusicSession}
 * by counting these 1-second ticks while a track plays (re-arming each tick) —
 * and auto-advances to the next track once the count reaches the track's
 * duration.
 *
 * Carries the NowPlaying audio epoch it was armed under: any state change that
 * (re)starts the heartbeat — start, resume, next/prev, auto-advance — bumps the
 * epoch, so a tick left over from a superseded chain is recognised as stale and
 * dropped. This prevents two heartbeats from running at once (which would double
 * the estimated position and auto-advance early). The discipline is the proven
 * screen-local audio-tick pattern relocated to the App.
 */
final readonly class NowPlayingTickMsg implements Msg
{
    public function __construct(public int $epoch = 0)
    {
    }
}

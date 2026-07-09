<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * One second elapsed during the App's audiobook playback. The
 * {@see \SugarCraft\Reel\AudioPlayer} exposes no playhead, so the App estimates
 * the elapsed position of its active {@see \Phlix\Console\Audio\AudiobookSession}
 * by counting these 1-second ticks while the book plays (re-arming each tick,
 * each adding 1000ms) — and reports/persists progress off that count.
 *
 * A DEDICATED tick Msg (distinct from the App's music {@see NowPlayingTickMsg})
 * so the two never cross-fire a tick.
 *
 * Carries the audio epoch it was armed under: any state change that
 * (re)starts the heartbeat (play, chapter-seek, resume) bumps the epoch, so a
 * tick left over from a superseded chain is recognised as stale and dropped —
 * preventing two heartbeats from running at once (which would double the
 * estimated position and the progress reports).
 */
final readonly class AudiobookTickMsg implements Msg
{
    public function __construct(public int $epoch = 0)
    {
    }
}

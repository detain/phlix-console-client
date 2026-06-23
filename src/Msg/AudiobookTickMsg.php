<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * One second elapsed during audiobook playback. The
 * {@see \SugarCraft\Reel\AudioPlayer} exposes no playhead, so the
 * {@see \Phlix\Console\Screen\AudiobookDetailScreen} estimates the elapsed
 * position by counting these 1-second ticks while the book plays (re-arming
 * each tick) — and reports/persists progress off that count.
 *
 * A DEDICATED tick Msg (NOT the AlbumScreen {@see AudioTickMsg}) so the two
 * screens never cross-fire a tick if both ever coexist on the stack.
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

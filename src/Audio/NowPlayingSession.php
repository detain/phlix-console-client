<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Audio;

use SugarCraft\Reel\AudioPlayer;

/**
 * The App's active now-playing session — one of {@see MusicSession} (a music
 * track) or {@see AudiobookSession} (an audiobook). Owned by the
 * {@see \Phlix\Console\App} (not a screen) so playback persists as the user
 * navigates, and rendered by the persistent {@see \Phlix\Console\Ui\NowPlayingBar}
 * on the bottom row of every screen.
 *
 * Both kinds are immutable, clone-mutate value objects: every transition
 * (pause/resume, a position tick, an epoch bump) returns a copy; only
 * {@see teardown()} mutates in place — it stops the underlying
 * {@see AudioPlayer} subprocess (idempotent).
 *
 * The {@see epoch()} is the playback heartbeat generation: it is bumped on every
 * (re)start of the heartbeat so a tick armed by a superseded chain is dropped as
 * stale — guarding against two heartbeats running at once. Each kind has its OWN
 * dedicated tick Msg ({@see \Phlix\Console\Msg\NowPlayingTickMsg} for music,
 * {@see \Phlix\Console\Msg\AudiobookTickMsg} for audiobooks) so the two never
 * cross-fire; the App owns the epoch arithmetic and this object carries the value.
 */
interface NowPlayingSession
{
    /** The underlying audio subprocess wrapper. */
    public function player(): AudioPlayer;

    /** Whether playback is currently paused. */
    public function paused(): bool;

    /** The current heartbeat generation (an armed tick carries this epoch). */
    public function epoch(): int;

    /** The now-playing title (the track / current chapter). */
    public function title(): string;

    /** The now-playing subtitle (album · artist / author · narrator). */
    public function subtitle(): string;

    /** The formatted clock of the CURRENT position (`m:ss` / `h:mm:ss`). */
    public function positionLabel(): string;

    /** The formatted clock of the total duration, or `—` when unknown. */
    public function durationLabel(): string;

    /** A copy with the paused flag set to $paused. */
    public function withPaused(bool $paused): static;

    /** A copy carrying a new heartbeat generation $epoch. */
    public function withEpoch(int $epoch): static;

    /**
     * A copy advanced by ONE heartbeat: music +1 second, an audiobook +1000ms.
     * The App arms a 1-second tick, so one heartbeat is one playback second.
     */
    public function ticked(): static;

    /** True once the position has reached (or passed) a known duration. */
    public function endReached(): bool;

    /** Stop the underlying audio subprocess (idempotent — leaving the app calls this). */
    public function teardown(): void;
}

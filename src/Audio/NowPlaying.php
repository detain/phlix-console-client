<?php

declare(strict_types=1);

namespace Phlix\Console\Audio;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\Track;
use SugarCraft\Reel\AudioPlayer;

/**
 * The App's active music session — the one track currently playing, owned by the
 * {@see \Phlix\Console\App} (not the {@see \Phlix\Console\Screen\AlbumScreen}) so
 * playback persists as the user navigates. Rendered by the persistent
 * {@see \Phlix\Console\Ui\NowPlayingBar} on the bottom row of every screen.
 *
 * Immutable, clone-mutate (like a screen): every transition (pause/resume, a new
 * track, a position tick) returns a copy. Only {@see teardown()} mutates `$this`
 * in place — it stops the underlying {@see AudioPlayer} subprocess (idempotent).
 *
 * The {@see $epoch} is the playback heartbeat generation: it is bumped on every
 * (re)start of the heartbeat (new track / resume / auto-advance) so a
 * {@see \Phlix\Console\Msg\NowPlayingTickMsg} armed by a superseded chain is
 * dropped as stale — guarding against two heartbeats running at once. The App
 * owns the epoch arithmetic; this object just carries the current value.
 */
final class NowPlaying
{
    private bool $tornDown = false;

    /**
     * The {@see $album} is the only stable collaborator (readonly); the rest is
     * mutable view-state copied via clone-mutate (mirroring a screen, where
     * `$selected`/`$paused`/`$position`/`$audioEpoch` are non-readonly). PHP forbids
     * reassigning a readonly property even on a clone, so the mutated fields cannot
     * be readonly — the immutability is enforced by only ever exposing copies.
     */
    public function __construct(
        private AudioPlayer $player,
        private readonly Album $album,
        private int $trackIndex,
        private bool $paused,
        private int $positionSecs,
        private int $epoch,
    ) {
    }

    /**
     * The real factory: a sugar-reel {@see AudioPlayer} over the resolved stream
     * URL (it spawns ffplay/mpv on start(), or silently no-ops if neither is
     * installed). Lives here — the neutral audio home — rather than on a screen,
     * since the App now owns the music audio.
     *
     * @return \Closure(string $url, ?int $startMs=): AudioPlayer
     */
    public static function productionAudioFactory(): \Closure
    {
        return static fn (string $url, ?int $startMs = null): AudioPlayer => new AudioPlayer($url, $startMs);
    }

    // ---- accessors -----------------------------------------------------

    public function player(): AudioPlayer
    {
        return $this->player;
    }

    public function album(): Album
    {
        return $this->album;
    }

    public function trackIndex(): int
    {
        return $this->trackIndex;
    }

    public function paused(): bool
    {
        return $this->paused;
    }

    public function positionSecs(): int
    {
        return $this->positionSecs;
    }

    public function epoch(): int
    {
        return $this->epoch;
    }

    /** The track currently playing, or null when the index has fallen out of range. */
    public function track(): ?Track
    {
        return $this->album->tracks[$this->trackIndex] ?? null;
    }

    /** The now-playing title — the track's title (empty when the track is gone). */
    public function title(): string
    {
        return $this->track()?->title ?? '';
    }

    /**
     * The now-playing subtitle — the album name, plus the artist (`Album · Artist`)
     * when the album carries one.
     */
    public function subtitle(): string
    {
        $artist = $this->album->artist;
        if ($artist !== null && $artist !== '') {
            return $this->album->name . ' · ' . $artist;
        }

        return $this->album->name;
    }

    /** The current track's duration in seconds, or null when unknown. */
    public function durationSecs(): ?int
    {
        return $this->track()?->durationSecs;
    }

    // ---- clone-mutate --------------------------------------------------

    public function withPaused(bool $paused): self
    {
        $next = clone $this;
        $next->paused = $paused;

        return $next;
    }

    public function withPositionSecs(int $positionSecs): self
    {
        $next = clone $this;
        $next->positionSecs = $positionSecs;

        return $next;
    }

    public function withEpoch(int $epoch): self
    {
        $next = clone $this;
        $next->epoch = $epoch;

        return $next;
    }

    /**
     * Switch to a different track of the same album under a new player (used by
     * next/prev and auto-advance): the position resets to 0 and paused clears.
     * The caller supplies the new epoch separately via {@see withEpoch()}.
     */
    public function withTrack(int $index, AudioPlayer $player): self
    {
        $next = clone $this;
        $next->player = $player;
        $next->trackIndex = $index;
        $next->positionSecs = 0;
        $next->paused = false;

        return $next;
    }

    /** Stop the underlying audio subprocess (idempotent — leaving the app calls this). */
    public function teardown(): void
    {
        if ($this->tornDown) {
            return;
        }
        $this->tornDown = true;
        $this->player->stop();
    }
}

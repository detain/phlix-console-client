<?php

declare(strict_types=1);

namespace Phlix\Console\Audio;

use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use SugarCraft\Reel\AudioPlayer;

/**
 * The App's active AUDIOBOOK session — the one audiobook currently playing,
 * owned by the {@see \Phlix\Console\App} (not the
 * {@see \Phlix\Console\Screen\AudiobookDetailScreen}) so playback persists as the
 * user navigates. One implementation of {@see NowPlayingSession}; the music twin
 * is {@see MusicSession}. Rendered by the persistent
 * {@see \Phlix\Console\Ui\NowPlayingBar} on the bottom row of every screen.
 *
 * An audiobook is ONE signed stream; chapters are seek markers into it. The
 * {@see AudioPlayer} exposes no playhead clock, so the elapsed position is
 * ESTIMATED by counting 1-second {@see \Phlix\Console\Msg\AudiobookTickMsg}s
 * (each adding 1000ms) while playing, and progress is reported (POSTed) throttled
 * off a tick count.
 *
 * Immutable, clone-mutate (like {@see MusicSession}): every transition returns a
 * copy. Only {@see teardown()} mutates `$this` in place — it stops the underlying
 * {@see AudioPlayer} subprocess (idempotent). The {@see $audiobook},
 * {@see $chapters} and {@see $audiobookId} are stable collaborators (readonly);
 * the rest is mutable view-state (PHP forbids reassigning a readonly property
 * even on a clone, so the mutated fields cannot be readonly — immutability is
 * enforced by only ever exposing copies).
 *
 * The {@see $epoch} is the playback heartbeat generation, bumped on every
 * (re)start (play / chapter-seek / resume / finish) so a tick from a superseded
 * chain is dropped as stale — never two heartbeats at once.
 */
final class AudiobookSession implements NowPlayingSession
{
    private bool $tornDown = false;

    /** The audiobook id (stable; == $audiobook->id), used for progress saves. */
    private readonly string $audiobookId;

    /**
     * @param list<AudiobookChapter> $chapters
     * @param int $positionMs Estimated ABSOLUTE position in MILLISECONDS.
     * @param int $ticksSinceReport ticks since the last throttled progress report.
     */
    public function __construct(
        private AudioPlayer $player,
        private readonly Audiobook $audiobook,
        private readonly array $chapters,
        private int $positionMs,
        private bool $paused,
        private int $epoch,
        private int $ticksSinceReport = 0,
    ) {
        $this->audiobookId = $audiobook->id;
    }

    // ---- accessors -----------------------------------------------------

    public function player(): AudioPlayer
    {
        return $this->player;
    }

    public function audiobook(): Audiobook
    {
        return $this->audiobook;
    }

    /** @return list<AudiobookChapter> */
    public function chapters(): array
    {
        return $this->chapters;
    }

    public function audiobookId(): string
    {
        return $this->audiobookId;
    }

    public function paused(): bool
    {
        return $this->paused;
    }

    public function positionMs(): int
    {
        return $this->positionMs;
    }

    public function epoch(): int
    {
        return $this->epoch;
    }

    public function ticksSinceReport(): int
    {
        return $this->ticksSinceReport;
    }

    /**
     * The now-playing title — the CURRENT chapter's title, falling back to the
     * audiobook title (a chapterless book, or a position past the last chapter).
     */
    public function title(): string
    {
        return $this->chapters[$this->currentChapterIndex()]->title ?? $this->audiobook->title;
    }

    /**
     * The now-playing subtitle — the author (plus ` · narrator` when present),
     * falling back to the audiobook title when neither is known.
     */
    public function subtitle(): string
    {
        $parts = [];
        if ($this->audiobook->author !== null && $this->audiobook->author !== '') {
            $parts[] = $this->audiobook->author;
        }
        if ($this->audiobook->narrator !== null && $this->audiobook->narrator !== '') {
            $parts[] = $this->audiobook->narrator;
        }

        return $parts === [] ? $this->audiobook->title : implode(' · ', $parts);
    }

    public function positionLabel(): string
    {
        return self::clock($this->positionMs);
    }

    public function durationLabel(): string
    {
        return $this->audiobook->durationMs !== null ? self::clock($this->audiobook->durationMs) : '—';
    }

    /** True once the elapsed position reaches the audiobook's known duration. */
    public function endReached(): bool
    {
        return $this->audiobook->durationMs !== null && $this->positionMs >= $this->audiobook->durationMs;
    }

    // ---- audiobook-specific progress -----------------------------------

    /**
     * The index of the chapter whose [startMs, endMs) contains the current
     * position (or the last chapter starting at/before it); 0 when no chapters.
     */
    public function currentChapterIndex(): int
    {
        if ($this->chapters === []) {
            return 0;
        }

        $index = 0;
        foreach ($this->chapters as $i => $chapter) {
            if ($this->positionMs >= $chapter->startMs && ($chapter->endMs <= 0 || $this->positionMs < $chapter->endMs)) {
                return $i;
            }
            if ($chapter->startMs <= $this->positionMs) {
                $index = $i;
            }
        }

        return $index;
    }

    /**
     * The indices of chapters fully behind the current position.
     *
     * @return list<int>
     */
    public function completedChapterIndices(): array
    {
        $completed = [];
        foreach ($this->chapters as $i => $chapter) {
            if ($chapter->endMs > 0 && $chapter->endMs <= $this->positionMs) {
                $completed[] = $i;
            }
        }

        return $completed;
    }

    /** The 0–100 completion percentage from the position over the duration. */
    public function percentComplete(): float
    {
        $duration = $this->audiobook->durationMs;

        return $duration !== null && $duration > 0
            ? min(100.0, $this->positionMs / $duration * 100)
            : 0.0;
    }

    /** True once ~10 ticks have elapsed since the last throttled progress save. */
    public function shouldReport(): bool
    {
        return $this->ticksSinceReport >= 10;
    }

    // ---- clone-mutate --------------------------------------------------

    public function withPaused(bool $paused): static
    {
        $next = clone $this;
        $next->paused = $paused;

        return $next;
    }

    public function withEpoch(int $epoch): static
    {
        $next = clone $this;
        $next->epoch = $epoch;

        return $next;
    }

    public function withPositionMs(int $positionMs): static
    {
        $next = clone $this;
        $next->positionMs = $positionMs;

        return $next;
    }

    /** Reset the throttle counter after a progress report has been issued. */
    public function withReported(): static
    {
        $next = clone $this;
        $next->ticksSinceReport = 0;

        return $next;
    }

    /** One playback second elapsed: +1000ms and one tick toward the throttle. */
    public function ticked(): static
    {
        $next = clone $this;
        $next->positionMs = $this->positionMs + 1000;
        $next->ticksSinceReport = $this->ticksSinceReport + 1;

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

    /** Milliseconds → "m:ss" (or "h:mm:ss" once an hour or longer). */
    private static function clock(int $ms): string
    {
        $total = intdiv(max(0, $ms), 1000);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $seconds = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%d:%02d', $minutes, $seconds);
    }
}

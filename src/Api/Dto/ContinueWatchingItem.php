<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A "Continue Watching" entry — a media item plus playback progress, mirroring
 * a row from `GET /api/v1/users/me/continue-watching`. Immutable.
 */
final readonly class ContinueWatchingItem
{
    public function __construct(
        public MediaItem $item,
        public int $positionTicks,
        public int $durationTicks,
        public string $playbackStatus,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            item: MediaItem::fromContinueWatching($row),
            positionTicks: Coerce::int($row['position_ticks'] ?? 0),
            durationTicks: Coerce::int($row['duration_ticks'] ?? 0),
            playbackStatus: Coerce::str($row['playback_status'] ?? ''),
        );
    }

    /** Playback progress as a fraction in [0, 1]. */
    public function progress(): float
    {
        if ($this->durationTicks <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->positionTicks / $this->durationTicks));
    }
}

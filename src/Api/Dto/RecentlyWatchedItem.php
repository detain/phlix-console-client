<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A recently-watched item from GET /api/v1/users/me/recently-watched.
 * Mirrors the row shape from the playback_state + media_items JOIN.
 */
final readonly class RecentlyWatchedItem
{
    public function __construct(
        public string $id,
        public string $mediaItemId,
        public string $name,
        public string $type,
        public int $positionTicks,
        public int $durationTicks,
        public string $playbackStatus,
        public float $progressPercent,
        public string $updatedAt,
        /** @var array<string, mixed>|null */
        public ?array $metadata = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $rawJson = $row['metadata_json'] ?? null;
        $metadata = null;
        if (is_string($rawJson) && $rawJson !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        } elseif (isset($row['metadata']) && is_array($row['metadata'])) {
            $metadata = $row['metadata'];
        }

        return new self(
            id: Coerce::str($row['id'] ?? ''),
            mediaItemId: Coerce::str($row['media_item_id'] ?? ''),
            name: Coerce::str($row['name'] ?? ''),
            type: Coerce::str($row['type'] ?? ''),
            positionTicks: Coerce::int($row['position_ticks'] ?? 0),
            durationTicks: Coerce::int($row['duration_ticks'] ?? 0),
            playbackStatus: Coerce::str($row['playback_status'] ?? ''),
            progressPercent: Coerce::float($row['progress_percent'] ?? 0.0),
            updatedAt: Coerce::str($row['updated_at'] ?? ''),
            metadata: $metadata,
        );
    }

    /** Progress as a fraction in [0, 1]. */
    public function progress(): float
    {
        if ($this->durationTicks <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->positionTicks / $this->durationTicks));
    }

    /** Remaining seconds. */
    public function remainingSeconds(): int
    {
        if ($this->durationTicks <= 0) {
            return 0;
        }

        $positionSeconds = $this->positionTicks / 10_000_000;
        $durationSeconds = $this->durationTicks / 10_000_000;

        return max(0, (int) ($durationSeconds - $positionSeconds));
    }
}

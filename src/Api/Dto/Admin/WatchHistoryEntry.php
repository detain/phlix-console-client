<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One row from the admin cross-user watch-history view.
 *
 * Mirrors a row from `GET /api/v1/admin/watch-history`. Tolerant; immutable.
 */
final readonly class WatchHistoryEntry
{
    public function __construct(
        public string $id,
        public string $mediaItemId,
        public string $mediaName,
        public string $mediaType,
        public string $libraryId,
        public string $userId,
        public string $username,
        public string $displayName,
        public string $profileName,
        public string $lastWatchedAt,
        public string $completedAt,
        public float $progressPercent,
        public string $playbackStatus,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            mediaItemId: Coerce::str($data['media_item_id'] ?? ''),
            mediaName: Coerce::str($data['media_name'] ?? ''),
            mediaType: Coerce::str($data['media_type'] ?? ''),
            libraryId: Coerce::str($data['library_id'] ?? ''),
            userId: Coerce::str($data['user_id'] ?? ''),
            username: Coerce::str($data['username'] ?? ''),
            displayName: Coerce::str($data['display_name'] ?? ''),
            profileName: Coerce::str($data['profile_name'] ?? ''),
            lastWatchedAt: Coerce::str($data['last_watched_at'] ?? ''),
            completedAt: Coerce::str($data['completed_at'] ?? ''),
            progressPercent: Coerce::float($data['progress_percent'] ?? null),
            playbackStatus: Coerce::str($data['playback_status'] ?? ''),
        );
    }

    /** Human-readable playback status symbol. */
    public function statusSymbol(): string
    {
        return match ($this->playbackStatus) {
            'completed' => '✓',
            'playing' => '▶',
            'paused' => '⏸',
            default => '○',
        };
    }
}

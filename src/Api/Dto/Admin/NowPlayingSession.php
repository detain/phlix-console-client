<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One active playback session in the admin dashboard's Now Playing panel,
 * mirroring an item of the server's `GET /api/v1/admin/dashboard/now-playing`
 * payload. Tolerant: the stats/session join can leave most fields null, so every
 * key defaults via {@see Coerce}. Immutable.
 */
final readonly class NowPlayingSession
{
    public function __construct(
        public string $streamId,
        public string $userId,
        public ?string $username,
        public string $mediaItemId,
        public ?string $mediaTitle,
        public ?string $mediaType,
        public int $positionTicks,
        public int $durationTicks,
        public float $progressPercent,
        public string $status,
        public ?string $deviceName,
        public ?string $deviceType,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            streamId: Coerce::str($data['stream_id'] ?? ''),
            userId: Coerce::str($data['user_id'] ?? ''),
            username: Coerce::nstr($data['username'] ?? null),
            mediaItemId: Coerce::str($data['media_item_id'] ?? ''),
            mediaTitle: Coerce::nstr($data['media_title'] ?? null),
            mediaType: Coerce::nstr($data['media_type'] ?? null),
            positionTicks: Coerce::int($data['position_ticks'] ?? 0),
            durationTicks: Coerce::int($data['duration_ticks'] ?? 0),
            progressPercent: Coerce::float($data['progress_percent'] ?? 0.0),
            status: Coerce::str($data['status'] ?? ''),
            deviceName: Coerce::nstr($data['device_name'] ?? null),
            deviceType: Coerce::nstr($data['device_type'] ?? null),
        );
    }

    /** A display label for the watcher: the username, falling back to the user id. */
    public function watcherLabel(): string
    {
        return $this->username ?? ($this->userId !== '' ? $this->userId : 'Unknown');
    }

    /** A display label for what's playing: the title, falling back to the media id. */
    public function titleLabel(): string
    {
        return $this->mediaTitle ?? ($this->mediaItemId !== '' ? $this->mediaItemId : 'Unknown');
    }
}

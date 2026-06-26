<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One row of the admin dashboard's Top Users leaderboard, mirroring an item of
 * `GET /api/v1/admin/dashboard/top-users`. Tolerant; immutable.
 */
final readonly class TopUser
{
    public function __construct(
        public string $userId,
        public ?string $username,
        public int $totalWatchTime,
        public int $playCount,
        public ?string $avatarUrl,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: Coerce::str($data['user_id'] ?? ''),
            username: Coerce::nstr($data['username'] ?? null),
            totalWatchTime: Coerce::int($data['total_watch_time'] ?? 0),
            playCount: Coerce::int($data['play_count'] ?? 0),
            avatarUrl: Coerce::nstr($data['avatar_url'] ?? null),
        );
    }

    /** A display label for the user: the username, falling back to the id. */
    public function label(): string
    {
        return $this->username ?? ($this->userId !== '' ? $this->userId : 'Unknown');
    }
}

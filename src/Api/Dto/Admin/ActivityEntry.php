<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One entry of the admin dashboard's Recent Activity feed, mirroring an item of
 * `GET /api/v1/admin/dashboard/activity` (a merged playback / library / auth
 * event). Tolerant; immutable.
 */
final readonly class ActivityEntry
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        public string $id,
        public string $eventType,
        public string $category,
        public ?string $userId,
        public ?string $username,
        public array $details,
        public string $occurredAt,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            eventType: Coerce::str($data['event_type'] ?? ''),
            category: Coerce::str($data['category'] ?? ''),
            userId: Coerce::nstr($data['user_id'] ?? null),
            username: Coerce::nstr($data['username'] ?? null),
            details: Coerce::map($data['details'] ?? null),
            occurredAt: Coerce::str($data['occurred_at'] ?? ''),
        );
    }

    /** A display label for who acted: the username, the user id, or "System". */
    public function actorLabel(): string
    {
        return $this->username ?? ($this->userId ?? 'System');
    }
}

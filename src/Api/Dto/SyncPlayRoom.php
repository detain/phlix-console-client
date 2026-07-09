<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A public SyncPlay room returned from the rooms list endpoint.
 *
 * @readonly
 */
final readonly class SyncPlayRoom
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isPublic,
        public int $memberCount,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? $data['room_id'] ?? ''),
            name: Coerce::str($data['name'] ?? $data['room_name'] ?? ''),
            isPublic: Coerce::bool($data['is_public'] ?? $data['isPublic'] ?? true),
            memberCount: Coerce::int($data['member_count'] ?? $data['memberCount'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_public' => $this->isPublic,
            'member_count' => $this->memberCount,
        ];
    }
}

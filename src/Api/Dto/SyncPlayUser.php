<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A member in a SyncPlay room.
 *
 * @readonly
 */
final readonly class SyncPlayUser
{
    public function __construct(
        public string $sessionId,
        public string $displayName,
        public bool $isHost = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: Coerce::str($data['id'] ?? $data['session_id'] ?? ''),
            displayName: Coerce::str($data['name'] ?? $data['display_name'] ?? 'Anonymous'),
            isHost: Coerce::bool($data['is_host'] ?? $data['isHost'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->sessionId,
            'name' => $this->displayName,
            'is_host' => $this->isHost,
        ];
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * SyncPlay session returned after creating or joining a room.
 *
 * @readonly
 */
final readonly class SyncPlaySession
{
    public function __construct(
        public string $roomId,
        public string $sessionId,
        public string $serverUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            roomId: Coerce::str($data['room_id'] ?? ''),
            sessionId: Coerce::str($data['session_id'] ?? ''),
            serverUrl: Coerce::str($data['server_url'] ?? ''),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'room_id' => $this->roomId,
            'session_id' => $this->sessionId,
            'server_url' => $this->serverUrl,
        ];
    }
}

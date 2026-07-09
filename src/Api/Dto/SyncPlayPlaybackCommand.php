<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A playback command received from the SyncPlay WebSocket.
 *
 * @readonly
 */
final readonly class SyncPlayPlaybackCommand
{
    /**
     * @param 'play'|'pause'|'seek' $type
     * @param int $position Position in milliseconds (for seek, this is the target position)
     * @param int $timestamp Server timestamp in milliseconds
     */
    public function __construct(
        public string $type,
        public int $position,
        public int $timestamp,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = Coerce::str($data['type'] ?? '', 'play');

        return new self(
            type: $type === 'pause' ? 'pause' : ($type === 'seek' ? 'seek' : 'play'),
            position: Coerce::int($data['position'] ?? $data['to_position'] ?? 0),
            timestamp: Coerce::int($data['timestamp'] ?? $data['server_time'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'position' => $this->position,
            'timestamp' => $this->timestamp,
        ];
    }
}

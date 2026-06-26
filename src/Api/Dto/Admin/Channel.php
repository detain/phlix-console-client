<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One Live-TV channel, mirroring a row of `GET /api/v1/admin/livetv/channels` →
 * `{success, channels: [...]}` (top-level named key). The server returns the raw
 * `livetv_channels` row: `id, channel_id, name, number (int), callsign, type,
 * description, icon_url, visibility ('visible'|'hidden'), enabled (TINYINT 0/1)`.
 *
 * A channel is treated as DISABLED when `visibility === 'hidden'` OR
 * `enabled == 0` (the server maps the PUT `{enabled}` flag onto `visibility`).
 * Tolerant + immutable.
 */
final readonly class Channel
{
    public function __construct(
        public string $id,
        public string $channelId,
        public string $name,
        public int $number,
        public ?string $callsign,
        public string $type,
        public bool $enabled,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $visibility = Coerce::str($data['visibility'] ?? 'visible', 'visible');
        // `enabled` defaults to true when the column is absent so a row with only
        // a `visibility` still resolves; disabled = hidden OR an explicit 0.
        $enabledFlag = Coerce::bool($data['enabled'] ?? true);

        return new self(
            id: Coerce::str($data['id'] ?? ''),
            channelId: Coerce::str($data['channel_id'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            number: Coerce::int($data['number'] ?? 0),
            callsign: Coerce::nstr($data['callsign'] ?? null),
            type: Coerce::str($data['type'] ?? ''),
            enabled: $visibility !== 'hidden' && $enabledFlag,
        );
    }
}

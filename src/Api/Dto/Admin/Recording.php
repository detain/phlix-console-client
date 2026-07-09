<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One Live-TV recording, mirroring a row of
 * `GET /api/v1/admin/livetv/recordings` → `{success, recordings: [...]}`
 * (top-level named key). The server returns the raw `livetv_recordings` row:
 * `recording_id, channel_id, program_id, title, description, start_time (int),
 * end_time (int), status, storage_size (?int bytes), series_rule_id`.
 *
 * `storageSize` stays an int (bytes). Tolerant + immutable.
 */
final readonly class Recording
{
    public function __construct(
        public string $recordingId,
        public string $channelId,
        public string $title,
        public int $startTime,
        public int $endTime,
        public string $status,
        public ?int $storageSize,
        public ?string $seriesRuleId,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            recordingId: Coerce::str($data['recording_id'] ?? ''),
            channelId: Coerce::str($data['channel_id'] ?? ''),
            title: Coerce::str($data['title'] ?? ''),
            startTime: Coerce::int($data['start_time'] ?? 0),
            endTime: Coerce::int($data['end_time'] ?? 0),
            status: Coerce::str($data['status'] ?? '', ''),
            storageSize: Coerce::nint($data['storage_size'] ?? null),
            seriesRuleId: Coerce::nstr($data['series_rule_id'] ?? null),
        );
    }

    /** A title-cased status label ("Scheduled", "Recording", …). */
    public function statusLabel(): string
    {
        return $this->status === '' ? 'Unknown' : ucfirst($this->status);
    }
}

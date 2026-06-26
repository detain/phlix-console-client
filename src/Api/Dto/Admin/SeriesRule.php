<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One Live-TV series-recording rule, mirroring a row of
 * `GET /api/v1/admin/livetv/series-rules` → `{success, rules: [...]}` (top-level
 * named key). The server returns the raw `livetv_series_rules` row: `rule_id,
 * series_id, channel_id, title, priority (int), max_recordings (?int),
 * days_ahead (int), is_active (TINYINT 0/1)`.
 *
 * Tolerant: `is_active` accepts 0|1, "0"|"1", true|false. Immutable.
 */
final readonly class SeriesRule
{
    public function __construct(
        public string $ruleId,
        public string $seriesId,
        public ?string $channelId,
        public string $title,
        public int $priority,
        public ?int $maxRecordings,
        public int $daysAhead,
        public bool $isActive,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ruleId: Coerce::str($data['rule_id'] ?? ''),
            seriesId: Coerce::str($data['series_id'] ?? ''),
            channelId: Coerce::nstr($data['channel_id'] ?? null),
            title: Coerce::str($data['title'] ?? ''),
            priority: Coerce::int($data['priority'] ?? 0),
            maxRecordings: Coerce::nint($data['max_recordings'] ?? null),
            daysAhead: Coerce::int($data['days_ahead'] ?? 0),
            isActive: Coerce::bool($data['is_active'] ?? false),
        );
    }
}

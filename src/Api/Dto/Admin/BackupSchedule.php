<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The backup schedule, mirroring `GET/PUT /api/v1/admin/backup/schedule` →
 * `{success, data: {auto_backup_interval_days, retention_count,
 * next_scheduled_backup?, next_scheduled_backup_iso?}}` (the
 * {@see \Phlix\Server\Http\Controllers\Admin\BackupController} is enveloped, so
 * the payload lives under `data`).
 *
 * The PUT response is thinner (only `auto_backup_interval_days` +
 * `retention_count`, no next-run); the defensive `fromArray` tolerates the
 * missing next-run key by leaving {@see $nextScheduledBackup} null.
 *
 * Immutable. The ISO mirror of the next run is preferred (human-readable); the
 * raw unix `next_scheduled_backup` is ignored when the ISO form is present.
 */
final readonly class BackupSchedule
{
    public function __construct(
        public int $autoBackupIntervalDays,
        public int $retentionCount,
        public ?string $nextScheduledBackup,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            autoBackupIntervalDays: Coerce::int($data['auto_backup_interval_days'] ?? 0),
            retentionCount: Coerce::int($data['retention_count'] ?? 0),
            nextScheduledBackup: Coerce::nstr($data['next_scheduled_backup_iso'] ?? null),
        );
    }
}

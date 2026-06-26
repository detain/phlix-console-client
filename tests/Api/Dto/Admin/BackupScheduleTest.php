<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\BackupSchedule;
use PHPUnit\Framework\TestCase;

final class BackupScheduleTest extends TestCase
{
    public function testMapsTheFullScheduleShape(): void
    {
        $schedule = BackupSchedule::fromArray([
            'auto_backup_interval_days' => 7,
            'retention_count' => 5,
            'next_scheduled_backup' => 1893456000,
            'next_scheduled_backup_iso' => '2030-01-01T00:00:00+00:00',
        ]);

        self::assertSame(7, $schedule->autoBackupIntervalDays);
        self::assertSame(5, $schedule->retentionCount);
        self::assertSame('2030-01-01T00:00:00+00:00', $schedule->nextScheduledBackup);
    }

    public function testToleratesMissingKeysWithDefaults(): void
    {
        $schedule = BackupSchedule::fromArray([]);

        self::assertSame(0, $schedule->autoBackupIntervalDays);
        self::assertSame(0, $schedule->retentionCount);
        self::assertNull($schedule->nextScheduledBackup);
    }

    public function testReadsTheThinUpdateShapeWithoutNextRun(): void
    {
        // The PUT response carries only the two settings.
        $schedule = BackupSchedule::fromArray([
            'auto_backup_interval_days' => '14',
            'retention_count' => '3',
        ]);

        self::assertSame(14, $schedule->autoBackupIntervalDays, 'numeric-string interval coerces');
        self::assertSame(3, $schedule->retentionCount);
        self::assertNull($schedule->nextScheduledBackup);
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\BackupSchedule;
use SugarCraft\Core\Msg;

/**
 * The backup schedule was updated successfully — carries the refreshed
 * {@see BackupSchedule}; the AdminBackupScreen swaps in the new schedule line
 * and toasts.
 */
final readonly class AdminBackupScheduleUpdatedMsg implements Msg
{
    public function __construct(
        public BackupSchedule $schedule,
    ) {
    }
}

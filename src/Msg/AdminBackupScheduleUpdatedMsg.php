<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\Backup;
use Phlix\Console\Api\Dto\Admin\BackupSchedule;
use SugarCraft\Core\Msg;

/**
 * The backup list AND the schedule arrived together (the AdminBackupScreen
 * fetches both in init / refresh) — the screen builds its table and schedule
 * line.
 */
final readonly class AdminBackupsLoadedMsg implements Msg
{
    /** @param list<Backup> $backups */
    public function __construct(
        public array $backups,
        public BackupSchedule $schedule,
    ) {
    }
}

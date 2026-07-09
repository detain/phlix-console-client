<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\LogFile;
use SugarCraft\Core\Msg;

/** The admin log file list arrived — the AdminLogsScreen builds its picker. */
final readonly class AdminLogFilesLoadedMsg implements Msg
{
    /** @param list<LogFile> $files */
    public function __construct(
        public array $files,
    ) {
    }
}

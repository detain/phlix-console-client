<?php

declare(strict_types=1);

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

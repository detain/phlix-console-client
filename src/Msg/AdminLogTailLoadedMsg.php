<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\LogTail;
use SugarCraft\Core\Msg;

/** A tailed log payload arrived — the AdminLogsScreen renders it in the viewport. */
final readonly class AdminLogTailLoadedMsg implements Msg
{
    public function __construct(
        public LogTail $tail,
    ) {
    }
}

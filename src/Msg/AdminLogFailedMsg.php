<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** A log list / tail fetch failed — the AdminLogsScreen shows the error + a retry. */
final readonly class AdminLogFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

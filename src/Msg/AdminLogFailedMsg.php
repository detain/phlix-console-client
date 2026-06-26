<?php

declare(strict_types=1);

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

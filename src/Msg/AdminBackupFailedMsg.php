<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The backup list / schedule fetch failed — the AdminBackupScreen shows the error + a retry. */
final readonly class AdminBackupFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

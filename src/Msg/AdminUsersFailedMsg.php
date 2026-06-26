<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The admin user list fetch failed — the AdminUsersScreen shows the error + a retry. */
final readonly class AdminUsersFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A Browse-time API call hit an unrecoverable auth failure (401 + refresh
 * exhausted). The App handles this by returning to login.
 */
final readonly class SessionExpiredMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A user action failed — carries the server `error` message (e.g. "Cannot
 * disable the last admin") to toast; the list is left unchanged.
 */
final readonly class AdminUserActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

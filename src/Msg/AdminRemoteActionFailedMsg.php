<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A remote-access action failed — carries the friendly server `message` (the
 * failure bodies use `message`, not `error`; the client re-surfaces it) to toast.
 */
final readonly class AdminRemoteActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

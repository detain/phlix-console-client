<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A remote-access action (relay enable/disable/ping, port-forward toggle,
 * subdomain claim/release, hub unenroll) succeeded — carries the confirmation
 * message to toast; the screen refetches all four statuses afterwards.
 */
final readonly class AdminRemoteActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

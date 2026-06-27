<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A profile action (create / update / delete / set-PIN / clear-PIN) succeeded.
 * Carries a ready-to-toast success message; the AdminUserProfilesScreen toasts it
 * and refetches the list.
 */
final readonly class AdminProfileActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

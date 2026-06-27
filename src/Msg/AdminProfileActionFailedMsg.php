<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A profile action failed — carries the server `error` text the
 * AdminUserProfilesScreen toasts (the list is left unchanged).
 */
final readonly class AdminProfileActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

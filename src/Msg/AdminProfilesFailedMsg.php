<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Loading the selected user's profiles failed — carries the ready-to-show error
 * line the AdminUserProfilesScreen renders (with a retry).
 */
final readonly class AdminProfilesFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

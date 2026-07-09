<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open the profiles of a selected user — emitted by the AdminUsersScreen (`P` on
 * the selected user) and handled at App level, which pushes an
 * AdminUserProfilesScreen for the named user (like OpenAdminPluginDetailMsg). The
 * label is the user's display name / username, shown in the screen header.
 */
final readonly class OpenAdminUserProfilesMsg implements Msg
{
    public function __construct(
        public string $userId,
        public string $userLabel,
    ) {
    }
}

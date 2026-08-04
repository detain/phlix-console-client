<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open the parental controls for a selected profile — emitted by the
 * AdminUserProfilesScreen (`C` on the selected profile) and handled at App
 * level, which pushes a ParentalControlsScreen for the named profile.
 */
final readonly class OpenAdminParentalControlsMsg implements Msg
{
    public function __construct(
        public string $profileId,
        public string $profileName,
    ) {
    }
}

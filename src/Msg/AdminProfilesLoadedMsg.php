<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\Profile;
use SugarCraft\Core\Msg;

/**
 * The selected user's profiles loaded — carries the list the
 * AdminUserProfilesScreen renders.
 */
final readonly class AdminProfilesLoadedMsg implements Msg
{
    /** @param list<Profile> $profiles */
    public function __construct(
        public array $profiles,
    ) {
    }
}

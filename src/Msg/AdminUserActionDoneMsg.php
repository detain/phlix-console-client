<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A user action succeeded. Carries the server `message` to toast and, for a
 * password reset, the once-shown `revealedPassword` (null for every other
 * action) so the AdminUsersScreen can surface it prominently. The screen
 * refetches the list after applying this.
 */
final readonly class AdminUserActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
        public ?string $revealedPassword = null,
    ) {
    }
}

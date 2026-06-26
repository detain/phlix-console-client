<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Admin\AdminUser;
use SugarCraft\Core\Msg;

/** The admin user list arrived — the AdminUsersScreen builds its table. */
final readonly class AdminUsersLoadedMsg implements Msg
{
    /** @param list<AdminUser> $users */
    public function __construct(
        public array $users,
    ) {
    }
}

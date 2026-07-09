<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Admin\AdminUser;

/**
 * A destructive user action that has been armed and is awaiting an inline (y/n)
 * confirmation on the {@see AdminUsersScreen}'s status line. Immutable.
 */
final readonly class PendingAction
{
    public function __construct(
        public string $action,
        public AdminUser $user,
    ) {
    }

    /** The confirm prompt, e.g. "Delete user 'bob'? (y/n)". */
    public function prompt(): string
    {
        $name = $this->user->label();

        $verb = match ($this->action) {
            'delete' => "Delete user '{$name}'?",
            'reject' => "Reject (delete) pending user '{$name}'?",
            'disable' => "Disable user '{$name}'?",
            'set-admin' => $this->user->isAdmin
                ? "Remove admin from '{$name}'?"
                : "Make '{$name}' an admin?",
            default => "Confirm action on '{$name}'?",
        };

        return $verb . ' (y/n)';
    }
}

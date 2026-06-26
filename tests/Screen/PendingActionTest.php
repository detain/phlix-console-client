<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Dto\Admin\AdminUser;
use Phlix\Console\Screen\PendingAction;
use PHPUnit\Framework\TestCase;

final class PendingActionTest extends TestCase
{
    private function user(bool $isAdmin = false): AdminUser
    {
        return AdminUser::fromArray(['username' => 'bob', 'is_admin' => $isAdmin]);
    }

    public function testPromptForEachKnownAction(): void
    {
        self::assertSame("Delete user 'bob'? (y/n)", (new PendingAction('delete', $this->user()))->prompt());
        self::assertSame("Reject (delete) pending user 'bob'? (y/n)", (new PendingAction('reject', $this->user()))->prompt());
        self::assertSame("Disable user 'bob'? (y/n)", (new PendingAction('disable', $this->user()))->prompt());
        self::assertSame("Make 'bob' an admin? (y/n)", (new PendingAction('set-admin', $this->user(false)))->prompt());
        self::assertSame("Remove admin from 'bob'? (y/n)", (new PendingAction('set-admin', $this->user(true)))->prompt());
    }

    public function testPromptFallsBackForAnUnknownAction(): void
    {
        $action = new PendingAction('mystery', $this->user());

        self::assertSame("Confirm action on 'bob'? (y/n)", $action->prompt());
        self::assertSame('mystery', $action->action);
    }
}

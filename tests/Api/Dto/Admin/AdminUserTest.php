<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\AdminUser;
use PHPUnit\Framework\TestCase;

final class AdminUserTest extends TestCase
{
    public function testFromArrayMapsAFullRow(): void
    {
        $u = AdminUser::fromArray([
            'id' => 'u-1',
            'username' => 'bob',
            'email' => 'bob@example.com',
            'display_name' => 'Bob B.',
            'is_admin' => 1,
            'status' => 'active',
            'created_at' => '2026-06-01 10:00:00',
            'last_login' => '2026-06-26 09:00:00',
        ]);

        self::assertSame('u-1', $u->id);
        self::assertSame('bob', $u->username);
        self::assertSame('bob@example.com', $u->email);
        self::assertSame('Bob B.', $u->displayName);
        self::assertTrue($u->isAdmin);
        self::assertSame('active', $u->status);
        self::assertSame('2026-06-01 10:00:00', $u->createdAt);
        self::assertSame('2026-06-26 09:00:00', $u->lastLoginAt);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $u = AdminUser::fromArray([]);

        self::assertSame('', $u->id);
        self::assertSame('', $u->username);
        self::assertSame('', $u->email);
        self::assertNull($u->displayName);
        self::assertFalse($u->isAdmin);
        self::assertSame('active', $u->status);
        self::assertNull($u->createdAt);
        self::assertNull($u->lastLoginAt);
    }

    public function testIsAdminCoercesAssortedTruthyEncodings(): void
    {
        self::assertTrue(AdminUser::fromArray(['is_admin' => '1'])->isAdmin);
        self::assertTrue(AdminUser::fromArray(['is_admin' => true])->isAdmin);
        self::assertFalse(AdminUser::fromArray(['is_admin' => '0'])->isAdmin);
        self::assertFalse(AdminUser::fromArray(['is_admin' => 0])->isAdmin);
    }

    public function testLastLoginFallsBackToLastLoginAtKey(): void
    {
        $u = AdminUser::fromArray(['last_login_at' => '2026-06-26 12:00:00']);

        self::assertSame('2026-06-26 12:00:00', $u->lastLoginAt);
    }

    public function testLastLoginPrefersTheRealColumn(): void
    {
        $u = AdminUser::fromArray([
            'last_login' => 'real',
            'last_login_at' => 'fallback',
        ]);

        self::assertSame('real', $u->lastLoginAt);
    }

    public function testLabelPrefersDisplayNameThenUsername(): void
    {
        self::assertSame('Bob B.', AdminUser::fromArray(['display_name' => 'Bob B.', 'username' => 'bob'])->label());
        self::assertSame('bob', AdminUser::fromArray(['username' => 'bob'])->label());
    }

    public function testRoleLabel(): void
    {
        self::assertSame('Admin', AdminUser::fromArray(['is_admin' => 1])->roleLabel());
        self::assertSame('User', AdminUser::fromArray(['is_admin' => 0])->roleLabel());
    }
}

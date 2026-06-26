<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\TopUser;
use PHPUnit\Framework\TestCase;

final class TopUserTest extends TestCase
{
    public function testFromArrayMapsAFullRow(): void
    {
        $u = TopUser::fromArray([
            'user_id' => 'u-1',
            'username' => 'joe',
            'total_watch_time' => '3600',
            'play_count' => 12,
            'avatar_url' => 'https://x/a.png',
        ]);

        self::assertSame('u-1', $u->userId);
        self::assertSame('joe', $u->username);
        self::assertSame(3600, $u->totalWatchTime);
        self::assertSame(12, $u->playCount);
        self::assertSame('https://x/a.png', $u->avatarUrl);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $u = TopUser::fromArray([]);

        self::assertSame('', $u->userId);
        self::assertNull($u->username);
        self::assertSame(0, $u->totalWatchTime);
        self::assertSame(0, $u->playCount);
        self::assertNull($u->avatarUrl);
    }

    public function testLabelPrefersUsernameThenUserIdThenUnknown(): void
    {
        self::assertSame('joe', TopUser::fromArray(['username' => 'joe'])->label());
        self::assertSame('u-1', TopUser::fromArray(['user_id' => 'u-1'])->label());
        self::assertSame('Unknown', TopUser::fromArray([])->label());
    }
}

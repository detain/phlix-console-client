<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\CastMember;
use PHPUnit\Framework\TestCase;

final class CastMemberTest extends TestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $cast = new CastMember('Keanu Reeves', 'Neo', 'https://example.com/keanu.jpg');

        self::assertSame('Keanu Reeves', $cast->name);
        self::assertSame('Neo', $cast->role);
        self::assertSame('https://example.com/keanu.jpg', $cast->profileUrl);
    }

    public function testRoleCanBeNull(): void
    {
        $cast = new CastMember('Actor Name', null, null);

        self::assertNull($cast->role);
        self::assertNull($cast->profileUrl);
    }
}

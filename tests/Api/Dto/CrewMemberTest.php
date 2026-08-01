<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\CrewMember;
use PHPUnit\Framework\TestCase;

final class CrewMemberTest extends TestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $crew = new CrewMember('James Cameron', 'Director', 'https://example.com/james.jpg');

        self::assertSame('James Cameron', $crew->name);
        self::assertSame('Director', $crew->job);
        self::assertSame('https://example.com/james.jpg', $crew->profileUrl);
    }

    public function testJobCanBeNull(): void
    {
        $crew = new CrewMember('Some Person', null, null);

        self::assertNull($crew->job);
        self::assertNull($crew->profileUrl);
    }
}

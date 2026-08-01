<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin\Parental;

use Phlix\Console\Api\Dto\Admin\Parental\ProfileTag;
use PHPUnit\Framework\TestCase;

final class ProfileTagTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $tag = ProfileTag::fromArray([
            'id' => 1,
            'profile_id' => 5,
            'tag' => 'violence',
            'tag_type' => 'blocked',
        ]);

        self::assertSame(1, $tag->id);
        self::assertSame(5, $tag->profileId);
        self::assertSame('violence', $tag->tag);
        self::assertSame('blocked', $tag->tagType);
    }

    public function testFromArrayDefaults(): void
    {
        $tag = ProfileTag::fromArray([]);

        self::assertSame(0, $tag->id);
        self::assertSame(0, $tag->profileId);
        self::assertSame('', $tag->tag);
        self::assertSame(ProfileTag::TYPE_BLOCKED, $tag->tagType);
    }

    public function testFromArrayWithAllowedType(): void
    {
        $tag = ProfileTag::fromArray([
            'id' => 2,
            'profile_id' => 3,
            'tag' => 'family',
            'tag_type' => 'allowed',
        ]);

        self::assertSame('allowed', $tag->tagType);
    }

    public function testToArrayReturnsAllFields(): void
    {
        $tag = new ProfileTag(
            id: 10,
            profileId: 20,
            tag: 'profanity',
            tagType: ProfileTag::TYPE_BLOCKED,
        );

        $arr = $tag->toArray();

        self::assertSame(10, $arr['id']);
        self::assertSame(20, $arr['profile_id']);
        self::assertSame('profanity', $arr['tag']);
        self::assertSame('blocked', $arr['tag_type']);
    }

    public function testTypeConstants(): void
    {
        self::assertSame('blocked', ProfileTag::TYPE_BLOCKED);
        self::assertSame('allowed', ProfileTag::TYPE_ALLOWED);
    }
}

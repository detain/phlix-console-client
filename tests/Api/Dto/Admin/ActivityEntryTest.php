<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\ActivityEntry;
use PHPUnit\Framework\TestCase;

final class ActivityEntryTest extends TestCase
{
    public function testFromArrayMapsAFullEntry(): void
    {
        $e = ActivityEntry::fromArray([
            'id' => 'a-1',
            'event_type' => 'playback_completed',
            'category' => 'playback',
            'user_id' => 'u-1',
            'username' => 'joe',
            'details' => ['media_title' => 'Heat', 'completed' => true],
            'occurred_at' => '2026-06-26 12:00:00',
        ]);

        self::assertSame('a-1', $e->id);
        self::assertSame('playback_completed', $e->eventType);
        self::assertSame('playback', $e->category);
        self::assertSame('u-1', $e->userId);
        self::assertSame('joe', $e->username);
        self::assertSame(['media_title' => 'Heat', 'completed' => true], $e->details);
        self::assertSame('2026-06-26 12:00:00', $e->occurredAt);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $e = ActivityEntry::fromArray([]);

        self::assertSame('', $e->id);
        self::assertSame('', $e->eventType);
        self::assertSame('', $e->category);
        self::assertNull($e->userId);
        self::assertNull($e->username);
        self::assertSame([], $e->details);
        self::assertSame('', $e->occurredAt);
    }

    public function testNonArrayDetailsBecomeEmptyMap(): void
    {
        $e = ActivityEntry::fromArray(['details' => 'garbage']);

        self::assertSame([], $e->details);
    }

    public function testActorLabelPrefersUsernameThenUserIdThenSystem(): void
    {
        self::assertSame('joe', ActivityEntry::fromArray(['username' => 'joe', 'user_id' => 'u-1'])->actorLabel());
        self::assertSame('u-1', ActivityEntry::fromArray(['user_id' => 'u-1'])->actorLabel());
        self::assertSame('System', ActivityEntry::fromArray([])->actorLabel());
    }
}

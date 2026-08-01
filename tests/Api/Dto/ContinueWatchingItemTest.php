<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\MediaItem;
use PHPUnit\Framework\TestCase;

final class ContinueWatchingItemTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $item = ContinueWatchingItem::fromArray([
            'id' => 'playback-1',
            'media_item_id' => 'movie-123',
            'name' => 'Inception',
            'type' => 'movie',
            'position_ticks' => 3600000000,
            'duration_ticks' => 10800000000,
            'playback_status' => 'playing',
            'poster_url' => 'https://srv/poster.jpg',
        ]);

        self::assertSame('movie-123', $item->item->id);
        self::assertSame('Inception', $item->item->name);
        self::assertSame(3600000000, $item->positionTicks);
        self::assertSame(10800000000, $item->durationTicks);
        self::assertSame('playing', $item->playbackStatus);
    }

    public function testProgressReturnsFraction(): void
    {
        $item = ContinueWatchingItem::fromArray([
            'media_item_id' => 'm1',
            'name' => 'Test',
            'type' => 'movie',
            'position_ticks' => 5400000000,
            'duration_ticks' => 10800000000,
            'playback_status' => 'playing',
        ]);

        self::assertSame(0.5, $item->progress());
    }

    public function testProgressClampedToOne(): void
    {
        $item = ContinueWatchingItem::fromArray([
            'media_item_id' => 'm1',
            'name' => 'Test',
            'type' => 'movie',
            'position_ticks' => 15000000000,
            'duration_ticks' => 10800000000,
            'playback_status' => 'playing',
        ]);

        self::assertSame(1.0, $item->progress());
    }

    public function testProgressReturnsZeroWhenDurationIsZero(): void
    {
        $item = ContinueWatchingItem::fromArray([
            'media_item_id' => 'm1',
            'name' => 'Test',
            'type' => 'movie',
            'position_ticks' => 1000,
            'duration_ticks' => 0,
            'playback_status' => 'playing',
        ]);

        self::assertSame(0.0, $item->progress());
    }

    public function testProgressReturnsZeroWhenDurationIsNegative(): void
    {
        $item = ContinueWatchingItem::fromArray([
            'media_item_id' => 'm1',
            'name' => 'Test',
            'type' => 'movie',
            'position_ticks' => 1000,
            'duration_ticks' => -100,
            'playback_status' => 'playing',
        ]);

        self::assertSame(0.0, $item->progress());
    }

    public function testFromArrayDefaults(): void
    {
        $item = ContinueWatchingItem::fromArray([
            'media_item_id' => 'm1',
            'name' => 'Test',
            'type' => 'movie',
        ]);

        self::assertSame('m1', $item->item->id);
        self::assertSame(0, $item->positionTicks);
        self::assertSame(0, $item->durationTicks);
        self::assertSame('', $item->playbackStatus);
    }
}

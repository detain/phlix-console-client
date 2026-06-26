<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\NowPlayingSession;
use PHPUnit\Framework\TestCase;

final class NowPlayingSessionTest extends TestCase
{
    public function testFromArrayMapsAFullSession(): void
    {
        $s = NowPlayingSession::fromArray([
            'stream_id' => 'st-1',
            'user_id' => 'u-1',
            'username' => 'joe',
            'media_item_id' => 'm-1',
            'media_title' => 'The Matrix',
            'media_type' => 'movie',
            'position_ticks' => '12000000',
            'duration_ticks' => 24000000,
            'progress_percent' => '50.0',
            'status' => 'playing',
            'device_name' => 'Console',
            'device_type' => 'console',
        ]);

        self::assertSame('st-1', $s->streamId);
        self::assertSame('u-1', $s->userId);
        self::assertSame('joe', $s->username);
        self::assertSame('m-1', $s->mediaItemId);
        self::assertSame('The Matrix', $s->mediaTitle);
        self::assertSame('movie', $s->mediaType);
        self::assertSame(12000000, $s->positionTicks, 'numeric string ticks coerce to int');
        self::assertSame(24000000, $s->durationTicks);
        self::assertSame(50.0, $s->progressPercent);
        self::assertSame('playing', $s->status);
        self::assertSame('Console', $s->deviceName);
        self::assertSame('console', $s->deviceType);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $s = NowPlayingSession::fromArray([]);

        self::assertSame('', $s->streamId);
        self::assertSame('', $s->userId);
        self::assertNull($s->username);
        self::assertSame('', $s->mediaItemId);
        self::assertNull($s->mediaTitle);
        self::assertNull($s->mediaType);
        self::assertSame(0, $s->positionTicks);
        self::assertSame(0, $s->durationTicks);
        self::assertSame(0.0, $s->progressPercent);
        self::assertSame('', $s->status);
        self::assertNull($s->deviceName);
        self::assertNull($s->deviceType);
    }

    public function testWatcherLabelPrefersUsernameThenUserIdThenUnknown(): void
    {
        self::assertSame('joe', NowPlayingSession::fromArray(['username' => 'joe', 'user_id' => 'u-1'])->watcherLabel());
        self::assertSame('u-1', NowPlayingSession::fromArray(['user_id' => 'u-1'])->watcherLabel());
        self::assertSame('Unknown', NowPlayingSession::fromArray([])->watcherLabel());
    }

    public function testTitleLabelPrefersTitleThenIdThenUnknown(): void
    {
        self::assertSame('Heat', NowPlayingSession::fromArray(['media_title' => 'Heat', 'media_item_id' => 'm-1'])->titleLabel());
        self::assertSame('m-1', NowPlayingSession::fromArray(['media_item_id' => 'm-1'])->titleLabel());
        self::assertSame('Unknown', NowPlayingSession::fromArray([])->titleLabel());
    }
}

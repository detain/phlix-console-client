<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\ActivityEntry;
use Phlix\Console\Api\Dto\Admin\AdminDashboard;
use Phlix\Console\Api\Dto\Admin\NowPlayingSession;
use Phlix\Console\Api\Dto\Admin\StorageSummary;
use Phlix\Console\Api\Dto\Admin\TopMediaItem;
use Phlix\Console\Api\Dto\Admin\TopUser;
use PHPUnit\Framework\TestCase;

final class AdminDashboardTest extends TestCase
{
    public function testConstructorHoldsTheTypedPanels(): void
    {
        $dashboard = new AdminDashboard(
            [NowPlayingSession::fromArray(['stream_id' => 'st-1'])],
            [TopUser::fromArray(['user_id' => 'u-1'])],
            [TopMediaItem::fromArray(['media_item_id' => 'm-1'])],
            StorageSummary::fromArray(['movie_bytes' => 10]),
            [ActivityEntry::fromArray(['id' => 'a-1'])],
        );

        self::assertCount(1, $dashboard->nowPlaying);
        self::assertSame('st-1', $dashboard->nowPlaying[0]->streamId);
        self::assertSame('u-1', $dashboard->topUsers[0]->userId);
        self::assertSame('m-1', $dashboard->topMedia[0]->mediaItemId);
        self::assertSame(10, $dashboard->storage->movieBytes);
        self::assertSame('a-1', $dashboard->activity[0]->id);
    }

    public function testFromPartsMapsEachListAndTheStorageMap(): void
    {
        $dashboard = AdminDashboard::fromParts(
            [['stream_id' => 'st-1', 'username' => 'joe']],
            [['user_id' => 'u-1', 'play_count' => 5]],
            [['media_item_id' => 'm-1', 'title' => 'Heat']],
            ['movie_bytes' => 1000, 'series_bytes' => 2000],
            [['id' => 'a-1', 'event_type' => 'login']],
        );

        self::assertContainsOnlyInstancesOf(NowPlayingSession::class, $dashboard->nowPlaying);
        self::assertSame('joe', $dashboard->nowPlaying[0]->username);
        self::assertSame(5, $dashboard->topUsers[0]->playCount);
        self::assertSame('Heat', $dashboard->topMedia[0]->title);
        self::assertSame(1000, $dashboard->storage->movieBytes);
        self::assertSame(2000, $dashboard->storage->seriesBytes);
        self::assertSame('login', $dashboard->activity[0]->eventType);
    }

    public function testFromPartsSkipsNonArrayRows(): void
    {
        $dashboard = AdminDashboard::fromParts(
            ['garbage', ['stream_id' => 'st-1'], 42, null],
            [],
            [],
            [],
            [],
        );

        self::assertCount(1, $dashboard->nowPlaying, 'non-array rows are skipped');
        self::assertSame('st-1', $dashboard->nowPlaying[0]->streamId);
    }

    public function testFromPartsTolerantOfEmptyPayloads(): void
    {
        $dashboard = AdminDashboard::fromParts([], [], [], [], []);

        self::assertSame([], $dashboard->nowPlaying);
        self::assertSame([], $dashboard->topUsers);
        self::assertSame([], $dashboard->topMedia);
        self::assertSame(0, $dashboard->storage->movieBytes, 'an empty storage map zeroes the totals');
        self::assertSame([], $dashboard->activity);
    }
}

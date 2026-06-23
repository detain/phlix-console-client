<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\TranscodeJob;
use PHPUnit\Framework\TestCase;

final class TranscodeJobTest extends TestCase
{
    public function testFromArrayMapsFields(): void
    {
        $job = TranscodeJob::fromArray([
            'job_id' => 'j1',
            'status' => 'running',
            'master_url' => '/hls/j1/master.m3u8',
            'progress' => 37,
            'playlist_ready' => true,
        ]);

        self::assertSame('j1', $job->jobId);
        self::assertSame('running', $job->status);
        self::assertSame('/hls/j1/master.m3u8', $job->masterUrl);
        self::assertSame(37.0, $job->progress);
        self::assertTrue($job->playlistReady);
    }

    public function testIsPlayableWhenPlaylistReadyWithAMaster(): void
    {
        $job = TranscodeJob::fromArray(['status' => 'running', 'playlist_ready' => true, 'master_url' => '/m.m3u8']);
        self::assertTrue($job->isPlayable());
    }

    public function testIsPlayableWhenCompleted(): void
    {
        $job = TranscodeJob::fromArray(['status' => 'completed', 'master_url' => '/m.m3u8']);
        self::assertTrue($job->isPlayable());
    }

    public function testNotPlayableWithoutAMasterUrl(): void
    {
        $job = TranscodeJob::fromArray(['status' => 'completed', 'playlist_ready' => true, 'master_url' => '']);
        self::assertFalse($job->isPlayable(), 'no master URL → not playable');
    }

    public function testNotPlayableWhileStillRunning(): void
    {
        $job = TranscodeJob::fromArray(['status' => 'running', 'playlist_ready' => false, 'master_url' => '/m.m3u8']);
        self::assertFalse($job->isPlayable());
    }

    public function testIsFailed(): void
    {
        self::assertTrue(TranscodeJob::fromArray(['status' => 'failed'])->isFailed());
        self::assertFalse(TranscodeJob::fromArray(['status' => 'running'])->isFailed());
    }
}

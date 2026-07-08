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
        self::assertSame([], $job->variants, 'no variants field → empty ladder');
    }

    public function testFromArrayDecodesTheVariantLadder(): void
    {
        $job = TranscodeJob::fromArray([
            'job_id' => 'j1',
            'status' => 'running',
            'master_url' => '/hls/j1/master.m3u8',
            'variants' => [
                ['id' => '1080p', 'label' => '1080p', 'url' => '/hls/j1/media_v1080p.m3u8'],
                ['id' => '720p', 'label' => '720p', 'url' => '/hls/j1/media_v720p.m3u8'],
            ],
        ]);

        self::assertCount(2, $job->variants);
        self::assertSame('1080p', $job->variants[0]->id, 'highest-first order is preserved');
        self::assertSame('/hls/j1/media_v720p.m3u8', $job->variants[1]->url);
    }

    public function testLegacyNullVariantsDecodeToEmpty(): void
    {
        $job = TranscodeJob::fromArray(['job_id' => 'j1', 'status' => 'running', 'variants' => null]);

        self::assertSame([], $job->variants);
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

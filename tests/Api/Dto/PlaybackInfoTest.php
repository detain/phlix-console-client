<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\PlaybackInfo;
use Phlix\Console\Api\Dto\StreamAudioTrack;
use PHPUnit\Framework\TestCase;

final class PlaybackInfoTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $info = PlaybackInfo::fromArray([
            'id' => 'playback-1',
            'name' => 'Sintel',
            'type' => 'movie',
            'media_sources' => [
                ['id' => 'src1', 'path' => '/media/sintel.mkv'],
            ],
            'markers' => ['intro' => ['start' => 10, 'end' => 60]],
            'audio_tracks' => [
                ['id' => 'a1', 'codec' => 'aac', 'bitrate' => 128000, 'channels' => 2, 'language' => 'eng'],
            ],
        ]);

        self::assertSame('playback-1', $info->id);
        self::assertSame('Sintel', $info->name);
        self::assertSame('movie', $info->type);
        self::assertCount(1, $info->mediaSources);
        self::assertSame('/media/sintel.mkv', $info->mediaSources[0]['path']);
        self::assertSame(['intro' => ['start' => 10, 'end' => 60]], $info->markers);
        self::assertCount(1, $info->audioTracks);
    }

    public function testFromArrayDefaults(): void
    {
        $info = PlaybackInfo::fromArray([]);

        self::assertSame('', $info->id);
        self::assertSame('', $info->name);
        self::assertSame('', $info->type);
        self::assertSame([], $info->mediaSources);
        self::assertSame([], $info->markers);
        self::assertSame([], $info->audioTracks);
    }

    public function testFromArrayFiltersNonArrayMediaSources(): void
    {
        $info = PlaybackInfo::fromArray([
            'id' => 'p1',
            'media_sources' => [
                null,
                false,
                ['id' => 'src1'],
                'not-an-array',
            ],
        ]);

        self::assertCount(1, $info->mediaSources);
        self::assertSame('src1', $info->mediaSources[0]['id']);
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\StorageSummary;
use PHPUnit\Framework\TestCase;

final class StorageSummaryTest extends TestCase
{
    public function testFromArrayMapsAllByteFields(): void
    {
        $s = StorageSummary::fromArray([
            'movie_bytes' => 1000,
            'series_bytes' => '2000',
            'music_bytes' => 300,
            'photo_bytes' => 40,
            'transcode_cache_bytes' => 5,
            // Extra server keys (items, formatted_*) are ignored.
            'items' => [['media_type' => 'movie']],
            'formatted_transcode_cache' => '5 B',
        ]);

        self::assertSame(1000, $s->movieBytes);
        self::assertSame(2000, $s->seriesBytes, 'numeric string coerces to int');
        self::assertSame(300, $s->musicBytes);
        self::assertSame(40, $s->photoBytes);
        self::assertSame(5, $s->transcodeCacheBytes);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $s = StorageSummary::fromArray([]);

        self::assertSame(0, $s->movieBytes);
        self::assertSame(0, $s->seriesBytes);
        self::assertSame(0, $s->musicBytes);
        self::assertSame(0, $s->photoBytes);
        self::assertSame(0, $s->transcodeCacheBytes);
    }

    public function testMediaTotalExcludesTranscodeCache(): void
    {
        $s = StorageSummary::fromArray([
            'movie_bytes' => 1000,
            'series_bytes' => 2000,
            'music_bytes' => 300,
            'photo_bytes' => 40,
            'transcode_cache_bytes' => 5,
        ]);

        self::assertSame(3340, $s->mediaTotalBytes());
    }

    public function testTotalIncludesTranscodeCache(): void
    {
        $s = StorageSummary::fromArray([
            'movie_bytes' => 1000,
            'series_bytes' => 2000,
            'music_bytes' => 300,
            'photo_bytes' => 40,
            'transcode_cache_bytes' => 5,
        ]);

        self::assertSame(3345, $s->totalBytes());
    }
}

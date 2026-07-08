<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Rendition;
use PHPUnit\Framework\TestCase;

final class RenditionTest extends TestCase
{
    public function testFromArrayMapsTheFixedContract(): void
    {
        $r = Rendition::fromArray([
            'id' => '1080p',
            'label' => '1080p HD',
            'width' => 1920,
            'height' => 1080,
            'bitrate' => 8_000_000,
            'codecs' => 'avc1.640028,mp4a.40.2',
            'url' => '/hls/j1/media_v1080p.m3u8?exp=1&sig=abc',
            'is_original' => false,
            'is_copy' => false,
            'video_bitrate' => 7_500_000,
        ]);

        self::assertSame('1080p', $r->id);
        self::assertSame('1080p HD', $r->label);
        self::assertSame(1920, $r->width);
        self::assertSame(1080, $r->height);
        self::assertSame(8_000_000, $r->bitrate);
        self::assertSame('avc1.640028,mp4a.40.2', $r->codecs);
        self::assertSame('/hls/j1/media_v1080p.m3u8?exp=1&sig=abc', $r->url);
        self::assertFalse($r->isOriginal);
        self::assertFalse($r->isCopy);
        self::assertSame(7_500_000, $r->videoBitrate);
    }

    public function testFromArrayDefaultsAndNullableFields(): void
    {
        $r = Rendition::fromArray(['id' => 'original', 'is_original' => true, 'is_copy' => true]);

        self::assertSame('original', $r->id);
        self::assertTrue($r->isOriginal);
        self::assertTrue($r->isCopy);
        self::assertNull($r->width);
        self::assertNull($r->height);
        self::assertNull($r->bitrate);
        self::assertNull($r->url, 'a missing url decodes to null, not ""');
        self::assertNull($r->videoBitrate);
        self::assertSame('', $r->codecs);
    }

    public function testDisplayLabelFallsBackToId(): void
    {
        self::assertSame('720p', Rendition::fromArray(['id' => '720p'])->displayLabel());
        self::assertSame('4K', Rendition::fromArray(['id' => '2160p', 'label' => '4K'])->displayLabel());
    }

    public function testListFromArrayDropsNonArrayEntries(): void
    {
        $list = Rendition::listFromArray([
            ['id' => '1080p'],
            'bogus',
            42,
            ['id' => '720p'],
        ]);

        self::assertCount(2, $list);
        self::assertSame('1080p', $list[0]->id);
        self::assertSame('720p', $list[1]->id);
    }

    public function testListFromArrayNullOrScalarIsEmpty(): void
    {
        self::assertSame([], Rendition::listFromArray(null), 'a legacy null variants field → empty list');
        self::assertSame([], Rendition::listFromArray('nope'));
        self::assertSame([], Rendition::listFromArray(7));
    }
}

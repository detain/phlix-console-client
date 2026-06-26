<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\TopMediaItem;
use PHPUnit\Framework\TestCase;

final class TopMediaItemTest extends TestCase
{
    public function testFromArrayMapsAFullRow(): void
    {
        $m = TopMediaItem::fromArray([
            'media_item_id' => 'm-1',
            'title' => 'Heat',
            'type' => 'movie',
            'play_count' => '7',
            'total_duration' => 10000,
        ]);

        self::assertSame('m-1', $m->mediaItemId);
        self::assertSame('Heat', $m->title);
        self::assertSame('movie', $m->type);
        self::assertSame(7, $m->playCount);
        self::assertSame(10000, $m->totalDuration);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $m = TopMediaItem::fromArray([]);

        self::assertSame('', $m->mediaItemId);
        self::assertNull($m->title);
        self::assertNull($m->type);
        self::assertSame(0, $m->playCount);
        self::assertSame(0, $m->totalDuration);
    }

    public function testLabelPrefersTitleThenIdThenUnknown(): void
    {
        self::assertSame('Heat', TopMediaItem::fromArray(['title' => 'Heat'])->label());
        self::assertSame('m-1', TopMediaItem::fromArray(['media_item_id' => 'm-1'])->label());
        self::assertSame('Unknown', TopMediaItem::fromArray([])->label());
    }
}

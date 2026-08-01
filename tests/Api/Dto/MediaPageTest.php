<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaPage;
use PHPUnit\Framework\TestCase;

final class MediaPageTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $page = MediaPage::fromArray([
            'items' => [
                ['id' => 'm1', 'name' => 'Movie 1', 'type' => 'movie'],
                ['id' => 'm2', 'name' => 'Movie 2', 'type' => 'movie'],
            ],
            'total' => 100,
            'offset' => 0,
            'limit' => 2,
        ]);

        self::assertCount(2, $page->items);
        self::assertSame(100, $page->total);
        self::assertSame(0, $page->offset);
        self::assertSame(2, $page->limit);
    }

    public function testFromArrayDefaultsTotalToCountOfItems(): void
    {
        $page = MediaPage::fromArray([
            'items' => [
                ['id' => 'm1', 'name' => 'Movie 1', 'type' => 'movie'],
            ],
        ]);

        self::assertSame(1, $page->total);
        self::assertSame(0, $page->offset);
        self::assertSame(1, $page->limit);
    }

    public function testFromArrayFiltersNonArrayItems(): void
    {
        $page = MediaPage::fromArray([
            'items' => [
                null,
                false,
                ['id' => 'm1', 'name' => 'Movie 1', 'type' => 'movie'],
                'not-an-array',
            ],
            'total' => 10,
        ]);

        self::assertCount(1, $page->items);
        self::assertSame('m1', $page->items[0]->id);
    }

    public function testHasMoreReturnsTrueWhenMoreItemsExist(): void
    {
        $page = MediaPage::fromArray([
            'items' => [
                ['id' => 'm1', 'name' => 'Movie 1', 'type' => 'movie'],
            ],
            'total' => 10,
            'offset' => 0,
            'limit' => 1,
        ]);

        self::assertTrue($page->hasMore());
    }

    public function testHasMoreReturnsFalseWhenAtEnd(): void
    {
        $page = MediaPage::fromArray([
            'items' => [
                ['id' => 'm1', 'name' => 'Movie 1', 'type' => 'movie'],
            ],
            'total' => 1,
            'offset' => 0,
            'limit' => 1,
        ]);

        self::assertFalse($page->hasMore());
    }

    public function testHasMoreReturnsFalseWhenOffsetBeyondTotal(): void
    {
        $page = MediaPage::fromArray([
            'items' => [],
            'total' => 5,
            'offset' => 10,
            'limit' => 10,
        ]);

        self::assertFalse($page->hasMore());
    }
}

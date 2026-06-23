<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookPage;
use PHPUnit\Framework\TestCase;

final class AudiobookPageTest extends TestCase
{
    public function testFromArrayMapsAudiobooksLimitAndOffset(): void
    {
        $page = AudiobookPage::fromArray([
            'audiobooks' => [
                ['id' => 'a1', 'name' => 'x.m4b', 'metadata' => ['author' => 'A']],
                ['id' => 'a2', 'title' => 'Two', 'author' => 'B'],
            ],
            'limit' => 100,
            'offset' => 100,
        ]);

        self::assertContainsOnlyInstancesOf(Audiobook::class, $page->audiobooks);
        self::assertCount(2, $page->audiobooks);
        self::assertSame('a1', $page->audiobooks[0]->id);
        self::assertSame('A', $page->audiobooks[0]->author);
        self::assertSame('Two', $page->audiobooks[1]->title);
        self::assertSame(100, $page->limit);
        self::assertSame(100, $page->offset);
    }

    public function testNonArrayAudiobookRowsAreSkipped(): void
    {
        $page = AudiobookPage::fromArray([
            'audiobooks' => [
                ['id' => 'a1', 'name' => 'Good'],
                'garbage',
                42,
                null,
                ['id' => 'a2', 'name' => 'Also Good'],
            ],
        ]);

        self::assertCount(2, $page->audiobooks, 'non-array rows are skipped');
        self::assertSame('a1', $page->audiobooks[0]->id);
        self::assertSame('a2', $page->audiobooks[1]->id);
    }

    public function testMissingAudiobooksBecomeEmptyList(): void
    {
        $page = AudiobookPage::fromArray([]);

        self::assertSame([], $page->audiobooks);
        self::assertSame(0, $page->limit);
        self::assertSame(0, $page->offset);
    }

    public function testEmptyAudiobooksList(): void
    {
        $page = AudiobookPage::fromArray(['audiobooks' => [], 'limit' => 100, 'offset' => 0]);

        self::assertSame([], $page->audiobooks);
        self::assertSame(100, $page->limit);
        self::assertSame(0, $page->offset);
    }

    public function testNumericStringLimitAndOffset(): void
    {
        $page = AudiobookPage::fromArray(['audiobooks' => [], 'limit' => '50', 'offset' => '200']);

        self::assertSame(50, $page->limit);
        self::assertSame(200, $page->offset);
    }
}

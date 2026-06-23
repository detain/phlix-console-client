<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Book;
use Phlix\Console\Api\Dto\BookPage;
use PHPUnit\Framework\TestCase;

final class BookPageTest extends TestCase
{
    public function testFromArrayMapsBooksLimitAndOffset(): void
    {
        $page = BookPage::fromArray([
            'books' => [
                ['id' => 'b1', 'name' => 'a.epub', 'path' => '/x/a.epub', 'metadata' => ['title' => 'A']],
                ['id' => 'b2', 'name' => 'b.pdf', 'path' => '/x/b.pdf', 'metadata' => ['title' => 'B']],
            ],
            'limit' => 24,
            'offset' => 48,
        ]);

        self::assertContainsOnlyInstancesOf(Book::class, $page->books);
        self::assertCount(2, $page->books);
        self::assertSame('A', $page->books[0]->title);
        self::assertSame('epub', $page->books[0]->format);
        self::assertSame('B', $page->books[1]->title);
        self::assertSame(24, $page->limit);
        self::assertSame(48, $page->offset);
    }

    public function testNonArrayBookRowsAreSkipped(): void
    {
        $page = BookPage::fromArray([
            'books' => [
                ['id' => 'b1', 'name' => 'Good'],
                'garbage',
                42,
                null,
                ['id' => 'b2', 'name' => 'Also Good'],
            ],
        ]);

        self::assertCount(2, $page->books, 'non-array rows are skipped');
        self::assertSame('Good', $page->books[0]->title);
        self::assertSame('Also Good', $page->books[1]->title);
    }

    public function testMissingBooksBecomeEmptyList(): void
    {
        $page = BookPage::fromArray([]);

        self::assertSame([], $page->books);
        self::assertSame(0, $page->limit);
        self::assertSame(0, $page->offset);
    }

    public function testEmptyBooksList(): void
    {
        $page = BookPage::fromArray(['books' => [], 'limit' => 50, 'offset' => 0]);

        self::assertSame([], $page->books);
        self::assertSame(50, $page->limit);
        self::assertSame(0, $page->offset);
    }

    public function testNumericStringLimitAndOffset(): void
    {
        $page = BookPage::fromArray(['books' => [], 'limit' => '30', 'offset' => '60']);

        self::assertSame(30, $page->limit);
        self::assertSame(60, $page->offset);
    }
}

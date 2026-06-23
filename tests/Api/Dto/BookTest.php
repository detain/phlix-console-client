<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Book;
use PHPUnit\Framework\TestCase;

final class BookTest extends TestCase
{
    public function testMapsTheRawListShapeWithoutSignedUrls(): void
    {
        $book = Book::fromArray([
            'id' => 'b1',
            'name' => 'dune.epub', // raw filename
            'type' => 'book',
            'library_id' => 'lib-1',
            'parent_id' => null,
            'path' => '/books/scifi/dune.epub',
            'metadata' => [
                'title' => 'Dune',
                'author' => 'Frank Herbert',
                'cover_path' => '/var/data/covers/dune.jpg', // filesystem path — unusable
            ],
        ]);

        self::assertSame('b1', $book->id);
        self::assertSame('Dune', $book->title, 'metadata.title wins over the raw filename name');
        self::assertSame('Frank Herbert', $book->author);
        self::assertNull($book->coverUrl, 'the list shape carries no usable cover URL');
        self::assertNull($book->downloadUrl);
        self::assertNull($book->readUrl);
        self::assertSame('epub', $book->format, 'format derived from the path extension');
    }

    public function testMapsTheDetailShapeWithSignedUrls(): void
    {
        $book = Book::fromArray([
            'id' => 'b1',
            'name' => 'dune.epub',
            'type' => 'book',
            'path' => '/books/scifi/dune.epub',
            'metadata' => [
                'title' => 'Dune',
                'author' => 'Frank Herbert',
            ],
            'cover_url' => '/api/v1/books/b1/cover?sig=abc',
            'read_url' => '/api/v1/books/b1/read?sig=def',
            'download_url' => '/api/v1/books/b1/download?sig=ghi',
        ]);

        self::assertSame('b1', $book->id);
        self::assertSame('Dune', $book->title);
        self::assertSame('Frank Herbert', $book->author);
        self::assertSame('/api/v1/books/b1/cover?sig=abc', $book->coverUrl);
        self::assertSame('/api/v1/books/b1/read?sig=def', $book->readUrl);
        self::assertSame('/api/v1/books/b1/download?sig=ghi', $book->downloadUrl);
        self::assertSame('epub', $book->format);
    }

    public function testTitlePrefersMetadataThenName(): void
    {
        // metadata.title wins over name.
        self::assertSame('Meta', Book::fromArray([
            'name' => 'Name',
            'metadata' => ['title' => 'Meta'],
        ])->title);

        // no metadata.title → falls back to top-level name.
        self::assertSame('Name', Book::fromArray([
            'name' => 'Name',
        ])->title);
    }

    public function testMissingTitleEverywhereBecomesEmptyString(): void
    {
        self::assertSame('', Book::fromArray(['id' => 'b1'])->title);
    }

    public function testMissingAndNullFieldsBecomeNull(): void
    {
        $book = Book::fromArray(['id' => 'b1', 'name' => 'Bare']);

        self::assertSame('b1', $book->id);
        self::assertSame('Bare', $book->title);
        self::assertNull($book->author);
        self::assertNull($book->coverUrl);
        self::assertNull($book->downloadUrl);
        self::assertNull($book->readUrl);
        self::assertNull($book->format, 'no path → no format');
    }

    public function testMissingIdBecomesEmptyString(): void
    {
        self::assertSame('', Book::fromArray(['name' => 'No Id'])->id);
    }

    public function testFormatIsLowercasedFromTheExtension(): void
    {
        self::assertSame('pdf', Book::fromArray(['path' => '/books/Manual.PDF'])->format);
        self::assertSame('cbz', Book::fromArray(['path' => '/comics/issue-1.cbz'])->format);
    }

    public function testFormatIsNullWhenThePathHasNoExtension(): void
    {
        self::assertNull(Book::fromArray(['path' => '/books/no-extension'])->format);
    }

    public function testFormatIsNullWhenThePathIsEmpty(): void
    {
        self::assertNull(Book::fromArray(['path' => ''])->format);
    }

    public function testAuthorFallsBackToNullWhenMetadataIsAbsent(): void
    {
        self::assertNull(Book::fromArray(['id' => 'b1', 'name' => 'Untitled'])->author);
    }
}

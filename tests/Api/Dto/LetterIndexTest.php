<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\LetterBucket;
use Phlix\Console\Api\Dto\LetterIndex;
use PHPUnit\Framework\TestCase;

final class LetterIndexTest extends TestCase
{
    private function index(): LetterIndex
    {
        return LetterIndex::fromArray([
            'letters' => [
                ['letter' => '#', 'offset' => 0, 'count' => 3],
                ['letter' => 'A', 'offset' => 3, 'count' => 10],
                ['letter' => 'B', 'offset' => 13, 'count' => 0],
                ['letter' => 'C', 'offset' => 13, 'count' => 7],
            ],
            'total' => 20,
        ]);
    }

    public function testFromArrayMapsBucketsAndTotal(): void
    {
        $index = $this->index();

        self::assertCount(4, $index->letters);
        self::assertSame(20, $index->total);
        self::assertContainsOnlyInstancesOf(LetterBucket::class, $index->letters);
        self::assertSame('A', $index->letters[1]->letter);
        self::assertSame(3, $index->letters[1]->offset);
        self::assertSame(10, $index->letters[1]->count);
    }

    public function testFromArrayCoercesNumericStringsAndSkipsNonArrays(): void
    {
        $index = LetterIndex::fromArray([
            'letters' => [
                ['letter' => 'A', 'offset' => '5', 'count' => '2'],
                'garbage',
            ],
            'total' => '7',
        ]);

        self::assertCount(1, $index->letters);
        self::assertSame(5, $index->letters[0]->offset);
        self::assertSame(2, $index->letters[0]->count);
        self::assertSame(7, $index->total);
    }

    public function testOffsetForKnownAndUnknownLetters(): void
    {
        $index = $this->index();

        self::assertSame(3, $index->offsetFor('A'));
        self::assertSame(13, $index->offsetFor('C'));
        self::assertNull($index->offsetFor('Z'), 'absent letter → null');
    }

    public function testEnabledLettersExcludesEmptyBuckets(): void
    {
        self::assertSame(['#', 'A', 'C'], $this->index()->enabledLetters(), 'B has count 0');
    }

    public function testLetterAtMapsAbsoluteIndexToBucket(): void
    {
        $index = $this->index();

        self::assertSame('#', $index->letterAt(0));
        self::assertSame('#', $index->letterAt(2));
        self::assertSame('A', $index->letterAt(3));
        self::assertSame('A', $index->letterAt(12));
        self::assertSame('C', $index->letterAt(13), 'empty B is skipped; C owns offset 13');
        self::assertSame('C', $index->letterAt(19));
        self::assertNull($index->letterAt(20), 'past the end → null');
        self::assertNull($index->letterAt(-1));
    }

    public function testIsEmpty(): void
    {
        self::assertTrue(LetterIndex::fromArray(['letters' => [], 'total' => 0])->isEmpty());
        self::assertFalse($this->index()->isEmpty());
    }

    public function testBucketIsEmpty(): void
    {
        self::assertTrue((new LetterBucket('B', 13, 0))->isEmpty());
        self::assertFalse((new LetterBucket('A', 3, 10))->isEmpty());
    }
}

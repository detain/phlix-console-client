<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\LetterBucket;
use PHPUnit\Framework\TestCase;

final class LetterBucketTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $bucket = LetterBucket::fromArray([
            'letter' => 'M',
            'offset' => 500,
            'count' => 42,
        ]);

        self::assertSame('M', $bucket->letter);
        self::assertSame(500, $bucket->offset);
        self::assertSame(42, $bucket->count);
    }

    public function testFromArrayDefaults(): void
    {
        $bucket = LetterBucket::fromArray([]);

        self::assertSame('', $bucket->letter);
        self::assertSame(0, $bucket->offset);
        self::assertSame(0, $bucket->count);
    }

    public function testIsEmptyReturnsTrueWhenCountIsZero(): void
    {
        $bucket = LetterBucket::fromArray(['letter' => 'X', 'count' => 0]);

        self::assertTrue($bucket->isEmpty());
    }

    public function testIsEmptyReturnsFalseWhenCountIsPositive(): void
    {
        $bucket = LetterBucket::fromArray(['letter' => 'X', 'count' => 10]);

        self::assertFalse($bucket->isEmpty());
    }

    public function testIsEmptyReturnsTrueWhenCountIsNegative(): void
    {
        $bucket = LetterBucket::fromArray(['letter' => 'X', 'count' => -5]);

        self::assertTrue($bucket->isEmpty());
    }
}

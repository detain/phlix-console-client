<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\AudiobookChapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AudiobookChapterTest extends TestCase
{
    public function testMapsTheFormattedShapeWithItsOwnIndex(): void
    {
        $chapter = AudiobookChapter::fromArray([
            'index' => 4,
            'title' => 'The Gom Jabbar',
            'start_ms' => 600000,
            'end_ms' => 1200000,
            'duration_ms' => 600000,
        ], 0);

        self::assertSame(4, $chapter->index, 'the row index wins over the ordinal');
        self::assertSame('The Gom Jabbar', $chapter->title);
        self::assertSame(600000, $chapter->startMs);
        self::assertSame(1200000, $chapter->endMs);
        self::assertSame(600000, $chapter->durationMs);
    }

    public function testIndexFallsBackToTheOrdinalWhenAbsent(): void
    {
        // The raw detail chapters carry no index.
        $chapter = AudiobookChapter::fromArray([
            'title' => 'Untitled span',
            'start_ms' => 0,
            'end_ms' => 1000,
            'duration_ms' => 1000,
        ], 7);

        self::assertSame(7, $chapter->index);
    }

    public function testTitleFallsBackToChapterNumberUsingTheResolvedIndex(): void
    {
        // No title, index present → "Chapter {index + 1}".
        $withIndex = AudiobookChapter::fromArray(['index' => 4], 0);
        self::assertSame('Chapter 5', $withIndex->title);

        // No title, no index → uses the resolved ordinal for the number.
        $withOrdinal = AudiobookChapter::fromArray([], 2);
        self::assertSame('Chapter 3', $withOrdinal->title);
        self::assertSame(2, $withOrdinal->index);
    }

    public function testMsFieldsDefaultToZeroWhenAbsent(): void
    {
        $chapter = AudiobookChapter::fromArray(['index' => 0, 'title' => 'Intro'], 0);

        self::assertSame(0, $chapter->startMs);
        self::assertSame(0, $chapter->endMs);
        self::assertSame(0, $chapter->durationMs);
    }

    public function testMsFieldsCoerceFromNumericStrings(): void
    {
        $chapter = AudiobookChapter::fromArray([
            'index' => 1,
            'start_ms' => '1000',
            'end_ms' => '5000',
            'duration_ms' => '4000',
        ], 0);

        self::assertSame(1000, $chapter->startMs);
        self::assertSame(5000, $chapter->endMs);
        self::assertSame(4000, $chapter->durationMs);
    }

    #[DataProvider('durationLabelProvider')]
    public function testDurationLabel(int $durationMs, string $expected): void
    {
        $chapter = new AudiobookChapter(0, 'C', 0, 0, $durationMs);

        self::assertSame($expected, $chapter->durationLabel());
    }

    /** @return iterable<string, array{0: int, 1: string}> */
    public static function durationLabelProvider(): iterable
    {
        yield 'zero' => [0, '0:00'];
        yield 'sub-minute' => [59000, '0:59'];
        yield 'one minute' => [60000, '1:00'];
        yield 'just under an hour' => [3599000, '59:59'];
        yield 'one hour' => [3600000, '1:00:00'];
        yield 'past an hour' => [3661000, '1:01:01'];
    }
}

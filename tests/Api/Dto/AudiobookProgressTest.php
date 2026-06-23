<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\AudiobookProgress;
use PHPUnit\Framework\TestCase;

final class AudiobookProgressTest extends TestCase
{
    public function testMapsTheFullShape(): void
    {
        $progress = AudiobookProgress::fromArray([
            'audiobook_id' => 'a1',
            'user_id' => 'u1',
            'position_ms' => 123456,
            'current_chapter_index' => 3,
            'completed_chapters' => [0, 1, 2],
            'percent_complete' => 42.5,
            'last_played_at' => 1700000000,
        ]);

        self::assertSame('a1', $progress->audiobookId);
        self::assertSame('u1', $progress->userId);
        self::assertSame(123456, $progress->positionMs);
        self::assertSame(3, $progress->currentChapterIndex);
        self::assertSame([0, 1, 2], $progress->completedChapters);
        self::assertSame(42.5, $progress->percentComplete);
        self::assertSame(1700000000, $progress->lastPlayedAt);
    }

    public function testCompletedChaptersFiltersToInts(): void
    {
        $progress = AudiobookProgress::fromArray([
            'completed_chapters' => [0, '1', 'nope', 2.0, null, '3', 'x4'],
        ]);

        // Numeric strings become ints, non-numeric values are dropped, and the
        // result is a clean re-indexed list.
        self::assertSame([0, 1, 2, 3], $progress->completedChapters);
    }

    public function testCompletedChaptersIsEmptyWhenAbsentOrNonArray(): void
    {
        self::assertSame([], AudiobookProgress::fromArray([])->completedChapters);
        self::assertSame([], AudiobookProgress::fromArray(['completed_chapters' => 'oops'])->completedChapters);
        self::assertSame([], AudiobookProgress::fromArray(['completed_chapters' => null])->completedChapters);
    }

    public function testDefaultsWhenKeysAbsent(): void
    {
        $progress = AudiobookProgress::fromArray([]);

        self::assertSame('', $progress->audiobookId);
        self::assertSame('', $progress->userId);
        self::assertSame(0, $progress->positionMs);
        self::assertSame(0, $progress->currentChapterIndex);
        self::assertSame([], $progress->completedChapters);
        self::assertSame(0.0, $progress->percentComplete);
        self::assertNull($progress->lastPlayedAt);
    }

    public function testLastPlayedAtIsNullWhenNullOrAbsent(): void
    {
        self::assertNull(AudiobookProgress::fromArray(['last_played_at' => null])->lastPlayedAt);
        self::assertNull(AudiobookProgress::fromArray([])->lastPlayedAt);
    }

    public function testNumericStringScalarsCoerce(): void
    {
        $progress = AudiobookProgress::fromArray([
            'position_ms' => '5000',
            'current_chapter_index' => '2',
            'percent_complete' => '12.5',
            'last_played_at' => '1700000000',
        ]);

        self::assertSame(5000, $progress->positionMs);
        self::assertSame(2, $progress->currentChapterIndex);
        self::assertSame(12.5, $progress->percentComplete);
        self::assertSame(1700000000, $progress->lastPlayedAt);
    }
}

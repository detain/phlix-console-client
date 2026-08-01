<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Chapter;
use PHPUnit\Framework\TestCase;

final class ChapterTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $chapter = Chapter::fromArray([
            'start_seconds' => 60.5,
            'end_seconds' => 180.25,
            'title' => 'Chapter 1: The Beginning',
        ]);

        self::assertSame(60.5, $chapter->start);
        self::assertSame(180.25, $chapter->end);
        self::assertSame('Chapter 1: The Beginning', $chapter->title);
    }

    public function testFromArrayDefaults(): void
    {
        $chapter = Chapter::fromArray([]);

        self::assertSame(0.0, $chapter->start);
        self::assertSame(0.0, $chapter->end);
        self::assertSame('', $chapter->title);
    }

    public function testFromArrayWithNumericStrings(): void
    {
        $chapter = Chapter::fromArray([
            'start_seconds' => '120',
            'end_seconds' => '240.5',
            'title' => 'Test Chapter',
        ]);

        self::assertSame(120.0, $chapter->start);
        self::assertSame(240.5, $chapter->end);
    }
}

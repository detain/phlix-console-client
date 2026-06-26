<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\LogTail;
use PHPUnit\Framework\TestCase;

final class LogTailTest extends TestCase
{
    public function testMapsASingleFileTail(): void
    {
        $tail = LogTail::fromArray([
            'file' => 'app.log',
            'lines' => ['line one', 'line two'],
            'truncated' => true,
        ]);

        self::assertSame('app.log', $tail->file);
        self::assertSame([], $tail->files);
        self::assertSame(['line one', 'line two'], $tail->lines);
        self::assertTrue($tail->truncated);
    }

    public function testMapsAnAllTail(): void
    {
        $tail = LogTail::fromArray([
            'files' => ['app.log', 'error.log'],
            'lines' => ['app.log   hello', 'error.log boom'],
            'truncated' => false,
        ]);

        self::assertNull($tail->file);
        self::assertSame(['app.log', 'error.log'], $tail->files);
        self::assertSame(['app.log   hello', 'error.log boom'], $tail->lines);
        self::assertFalse($tail->truncated);
    }

    public function testCoercesTruncatedTruthyEncodings(): void
    {
        self::assertTrue(LogTail::fromArray(['truncated' => 1])->truncated);
        self::assertTrue(LogTail::fromArray(['truncated' => 'true'])->truncated);
        self::assertFalse(LogTail::fromArray(['truncated' => 0])->truncated);
    }

    public function testSkipsNonStringLineEntries(): void
    {
        $tail = LogTail::fromArray(['lines' => ['ok', 42, null, ['nested'], 'also ok']]);

        self::assertSame(['ok', '42', 'also ok'], $tail->lines);
    }

    public function testToleratesMissingKeys(): void
    {
        $tail = LogTail::fromArray([]);

        self::assertNull($tail->file);
        self::assertSame([], $tail->files);
        self::assertSame([], $tail->lines);
        self::assertFalse($tail->truncated);
    }
}

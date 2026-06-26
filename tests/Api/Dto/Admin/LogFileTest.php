<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\LogFile;
use PHPUnit\Framework\TestCase;

final class LogFileTest extends TestCase
{
    public function testMapsEveryField(): void
    {
        $file = LogFile::fromArray([
            'name' => 'app.log',
            'size' => 4096,
            'modified_at' => '2026-06-26T12:00:00-04:00',
        ]);

        self::assertSame('app.log', $file->name);
        self::assertSame(4096, $file->size);
        self::assertSame('2026-06-26T12:00:00-04:00', $file->modifiedAt);
    }

    public function testCoercesNumericStringSize(): void
    {
        $file = LogFile::fromArray(['name' => 'a.log', 'size' => '2048', 'modified_at' => 'x']);

        self::assertSame(2048, $file->size);
    }

    public function testToleratesMissingKeys(): void
    {
        $file = LogFile::fromArray([]);

        self::assertSame('', $file->name);
        self::assertSame(0, $file->size);
        self::assertSame('', $file->modifiedAt);
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\Backup;
use PHPUnit\Framework\TestCase;

final class BackupTest extends TestCase
{
    public function testMapsAFullListRow(): void
    {
        $backup = Backup::fromArray([
            'id' => 'b-1',
            'label' => 'pre-upgrade',
            'file_path' => '/var/backups/x.tar.gz',
            'size_bytes' => 1048576,
            'checksum_sha256' => 'abc',
            'is_s3' => 1,
            'created_at' => '2026-06-26 12:00:00',
            'expires_at' => null,
        ]);

        self::assertSame('b-1', $backup->id);
        self::assertSame('pre-upgrade', $backup->label);
        self::assertSame('2026-06-26 12:00:00', $backup->createdAt);
        self::assertSame(1048576, $backup->sizeBytes);
        self::assertTrue($backup->isS3);
        self::assertSame('pre-upgrade', $backup->displayLabel());
    }

    public function testToleratesMissingKeysWithDefaults(): void
    {
        $backup = Backup::fromArray([]);

        self::assertSame('', $backup->id);
        self::assertNull($backup->label);
        self::assertNull($backup->createdAt);
        self::assertSame(0, $backup->sizeBytes);
        self::assertFalse($backup->isS3);
    }

    public function testEmptyLabelIsNormalisedToNull(): void
    {
        // BackupManager stores '' when no label is given.
        $backup = Backup::fromArray(['id' => 'b-2', 'label' => '']);

        self::assertNull($backup->label);
        self::assertSame('b-2', $backup->displayLabel(), 'an unlabelled backup falls back to its id');
    }

    public function testReadsTheThinCreateShapeBackupId(): void
    {
        // The create endpoint returns `{backup_id, file_path, size_bytes}`.
        $backup = Backup::fromArray([
            'backup_id' => 'b-3',
            'file_path' => '/x.tar.gz',
            'size_bytes' => '2048',
        ]);

        self::assertSame('b-3', $backup->id);
        self::assertSame(2048, $backup->sizeBytes, 'numeric-string bytes coerce to int');
    }

    public function testCoercesTinyintIsS3(): void
    {
        self::assertTrue(Backup::fromArray(['is_s3' => '1'])->isS3);
        self::assertFalse(Backup::fromArray(['is_s3' => '0'])->isS3);
        self::assertTrue(Backup::fromArray(['is_s3' => true])->isS3);
    }
}

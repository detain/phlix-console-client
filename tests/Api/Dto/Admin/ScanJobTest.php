<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\ScanJob;
use PHPUnit\Framework\TestCase;

final class ScanJobTest extends TestCase
{
    public function testMapsAFullRow(): void
    {
        $job = ScanJob::fromArray([
            'id' => 'job-1',
            'library_id' => 'lib-1',
            'type' => 'rescan',
            'status' => 'running',
            'items_found' => '12',
            'items_added' => 3,
            'items_updated' => 1,
            'items_removed' => 2,
            'current_path' => '/media/a.mkv',
            'error' => null,
            'queued_at' => '2026-06-26 12:00:00',
            'started_at' => '2026-06-26 12:00:01',
            'completed_at' => null,
        ]);

        self::assertSame('job-1', $job->id);
        self::assertSame('lib-1', $job->libraryId);
        self::assertSame('rescan', $job->type);
        self::assertSame('running', $job->status);
        self::assertSame(12, $job->itemsFound, 'a numeric string is coerced to int');
        self::assertSame(3, $job->itemsAdded);
        self::assertSame(1, $job->itemsUpdated);
        self::assertSame(2, $job->itemsRemoved);
        self::assertSame('/media/a.mkv', $job->currentPath);
        self::assertNull($job->error);
        self::assertSame('2026-06-26 12:00:00', $job->queuedAt);
        self::assertSame('2026-06-26 12:00:01', $job->startedAt);
        self::assertNull($job->completedAt);
    }

    public function testToleratesAnEmptyRow(): void
    {
        $job = ScanJob::fromArray([]);

        self::assertSame('', $job->id);
        self::assertSame('', $job->libraryId);
        self::assertSame('', $job->type);
        self::assertSame('', $job->status);
        self::assertSame(0, $job->itemsFound);
        self::assertSame(0, $job->itemsAdded);
        self::assertSame(0, $job->itemsUpdated);
        self::assertSame(0, $job->itemsRemoved);
        self::assertNull($job->currentPath);
        self::assertNull($job->error);
        self::assertNull($job->queuedAt);
        self::assertNull($job->startedAt);
        self::assertNull($job->completedAt);
    }

    public function testIsActiveForQueuedAndRunning(): void
    {
        self::assertTrue(ScanJob::fromArray(['status' => 'queued'])->isActive());
        self::assertTrue(ScanJob::fromArray(['status' => 'running'])->isActive());
    }

    public function testIsNotActiveForCompletedFailedOrUnknown(): void
    {
        self::assertFalse(ScanJob::fromArray(['status' => 'completed'])->isActive());
        self::assertFalse(ScanJob::fromArray(['status' => 'failed'])->isActive());
        self::assertFalse(ScanJob::fromArray(['status' => ''])->isActive());
    }

    public function testSummaryIsTerseWithCounters(): void
    {
        $job = ScanJob::fromArray([
            'status' => 'running', 'items_found' => 12, 'items_added' => 3,
            'items_updated' => 1, 'items_removed' => 0,
        ]);

        self::assertSame('running · found 12, +3 ~1 -0', $job->summary());
    }

    public function testSummaryFallsBackToUnknownForAnEmptyStatus(): void
    {
        self::assertStringStartsWith('unknown · found 0', ScanJob::fromArray([])->summary());
    }
}

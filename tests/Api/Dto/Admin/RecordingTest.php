<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\Recording;
use PHPUnit\Framework\TestCase;

final class RecordingTest extends TestCase
{
    public function testFromArrayMapsAFullRow(): void
    {
        $r = Recording::fromArray([
            'recording_id' => 'rec-1',
            'channel_id' => 'wxyz.1',
            'program_id' => 'EP001.0001',
            'title' => 'The Show',
            'description' => 'desc',
            'start_time' => 1750000000,
            'end_time' => 1750003600,
            'status' => 'recording',
            'storage_size' => 1073741824,
            'series_rule_id' => 'sr-9',
        ]);

        self::assertSame('rec-1', $r->recordingId);
        self::assertSame('wxyz.1', $r->channelId);
        self::assertSame('The Show', $r->title);
        self::assertSame(1750000000, $r->startTime);
        self::assertSame(1750003600, $r->endTime);
        self::assertSame('recording', $r->status);
        self::assertSame(1073741824, $r->storageSize);
        self::assertSame('sr-9', $r->seriesRuleId);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $r = Recording::fromArray([]);

        self::assertSame('', $r->recordingId);
        self::assertSame('', $r->channelId);
        self::assertSame('', $r->title);
        self::assertSame(0, $r->startTime);
        self::assertSame(0, $r->endTime);
        self::assertSame('', $r->status);
        self::assertNull($r->storageSize);
        self::assertNull($r->seriesRuleId);
    }

    public function testStorageSizeStaysAnIntAndCoercesNumericString(): void
    {
        self::assertSame(2048, Recording::fromArray(['storage_size' => '2048'])->storageSize);
        self::assertNull(Recording::fromArray(['storage_size' => ''])->storageSize);
    }

    public function testStatusLabelTitleCasesAKnownStatus(): void
    {
        self::assertSame('Scheduled', Recording::fromArray(['status' => 'scheduled'])->statusLabel());
        self::assertSame('Recording', Recording::fromArray(['status' => 'recording'])->statusLabel());
    }

    public function testStatusLabelFallsBackToUnknown(): void
    {
        self::assertSame('Unknown', Recording::fromArray([])->statusLabel());
    }
}

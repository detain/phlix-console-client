<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\SeriesRule;
use PHPUnit\Framework\TestCase;

final class SeriesRuleTest extends TestCase
{
    public function testFromArrayMapsAFullRow(): void
    {
        $r = SeriesRule::fromArray([
            'rule_id' => 'sr-1',
            'series_id' => 'SH00112233',
            'channel_id' => 'wxyz.1',
            'title' => 'The Show',
            'priority' => 5,
            'max_recordings' => 10,
            'days_ahead' => 14,
            'is_active' => 1,
        ]);

        self::assertSame('sr-1', $r->ruleId);
        self::assertSame('SH00112233', $r->seriesId);
        self::assertSame('wxyz.1', $r->channelId);
        self::assertSame('The Show', $r->title);
        self::assertSame(5, $r->priority);
        self::assertSame(10, $r->maxRecordings);
        self::assertSame(14, $r->daysAhead);
        self::assertTrue($r->isActive);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $r = SeriesRule::fromArray([]);

        self::assertSame('', $r->ruleId);
        self::assertSame('', $r->seriesId);
        self::assertNull($r->channelId);
        self::assertSame('', $r->title);
        self::assertSame(0, $r->priority);
        self::assertNull($r->maxRecordings);
        self::assertSame(0, $r->daysAhead);
        self::assertFalse($r->isActive);
    }

    public function testIsActiveCoercesAssortedTruthyEncodings(): void
    {
        self::assertTrue(SeriesRule::fromArray(['is_active' => '1'])->isActive);
        self::assertTrue(SeriesRule::fromArray(['is_active' => true])->isActive);
        self::assertFalse(SeriesRule::fromArray(['is_active' => '0'])->isActive);
        self::assertFalse(SeriesRule::fromArray(['is_active' => 0])->isActive);
    }

    public function testMaxRecordingsCoercesNumericStringAndDropsEmpty(): void
    {
        self::assertSame(3, SeriesRule::fromArray(['max_recordings' => '3'])->maxRecordings);
        self::assertNull(SeriesRule::fromArray(['max_recordings' => ''])->maxRecordings);
    }
}

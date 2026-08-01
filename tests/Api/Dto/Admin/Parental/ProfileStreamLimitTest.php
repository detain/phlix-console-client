<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin\Parental;

use Phlix\Console\Api\Dto\Admin\Parental\ProfileStreamLimit;
use PHPUnit\Framework\TestCase;

final class ProfileStreamLimitTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $limit = ProfileStreamLimit::fromArray([
            'max_concurrent_streams' => 4,
            'max_total_bandwidth_kbps' => 100000,
        ]);

        self::assertSame(4, $limit->maxConcurrentStreams);
        self::assertSame(100000, $limit->maxTotalBandwidthKbps);
    }

    public function testFromArrayWithCamelCaseKeys(): void
    {
        $limit = ProfileStreamLimit::fromArray([
            'maxConcurrentStreams' => 2,
            'maxTotalBandwidthKbps' => 50000,
        ]);

        self::assertSame(2, $limit->maxConcurrentStreams);
        self::assertSame(50000, $limit->maxTotalBandwidthKbps);
    }

    public function testFromArrayDefaults(): void
    {
        $limit = ProfileStreamLimit::fromArray([]);

        self::assertSame(0, $limit->maxConcurrentStreams);
        self::assertNull($limit->maxTotalBandwidthKbps);
    }

    public function testToArrayReturnsAllFields(): void
    {
        $limit = new ProfileStreamLimit(3, 75000);

        $arr = $limit->toArray();

        self::assertSame(3, $arr['max_concurrent_streams']);
        self::assertSame(75000, $arr['max_total_bandwidth_kbps']);
    }

    public function testToArrayWithNullBandwidth(): void
    {
        $limit = new ProfileStreamLimit(2, null);

        $arr = $limit->toArray();

        self::assertSame(2, $arr['max_concurrent_streams']);
        self::assertNull($arr['max_total_bandwidth_kbps']);
    }
}

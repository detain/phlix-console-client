<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\Tuner;
use PHPUnit\Framework\TestCase;

final class TunerTest extends TestCase
{
    public function testFromArrayMapsAFullRow(): void
    {
        $t = Tuner::fromArray([
            'id' => 't-1',
            'tuner_id' => 'hdhr-AB12',
            'type' => 'hdhomerun',
            'name' => 'Living Room',
            'host' => '192.168.1.50',
            'port' => 5004,
            'device_id' => 'AB12',
            'enabled' => 1,
            'last_seen' => '2026-06-26 09:00:00',
            'status' => 'online',
            'capabilities' => '{}',
        ]);

        self::assertSame('t-1', $t->id);
        self::assertSame('hdhr-AB12', $t->tunerId);
        self::assertSame('hdhomerun', $t->type);
        self::assertSame('Living Room', $t->name);
        self::assertSame('192.168.1.50', $t->host);
        self::assertSame(5004, $t->port);
        self::assertTrue($t->enabled);
        self::assertSame('online', $t->status);
        self::assertSame('2026-06-26 09:00:00', $t->lastSeen);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $t = Tuner::fromArray([]);

        self::assertSame('', $t->id);
        self::assertSame('', $t->tunerId);
        self::assertSame('', $t->type);
        self::assertSame('', $t->name);
        self::assertNull($t->host);
        self::assertNull($t->port);
        self::assertFalse($t->enabled);
        self::assertSame('', $t->status);
        self::assertNull($t->lastSeen);
    }

    public function testEnabledCoercesAssortedTruthyEncodings(): void
    {
        self::assertTrue(Tuner::fromArray(['enabled' => '1'])->enabled);
        self::assertTrue(Tuner::fromArray(['enabled' => true])->enabled);
        self::assertFalse(Tuner::fromArray(['enabled' => '0'])->enabled);
        self::assertFalse(Tuner::fromArray(['enabled' => 0])->enabled);
    }

    public function testPortCoercesNumericStringAndDropsNonNumeric(): void
    {
        self::assertSame(5004, Tuner::fromArray(['port' => '5004'])->port);
        self::assertNull(Tuner::fromArray(['port' => ''])->port);
        self::assertNull(Tuner::fromArray(['port' => 'nope'])->port);
    }
}

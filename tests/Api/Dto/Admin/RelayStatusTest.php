<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\RelayStatus;
use PHPUnit\Framework\TestCase;

final class RelayStatusTest extends TestCase
{
    public function testMapsAConnectedStatus(): void
    {
        $relay = RelayStatus::fromArray([
            'connected' => true,
            'active' => true,
            'endpoint' => null,
            'establishedAt' => '2026-06-26T10:00:00+00:00',
        ]);

        self::assertTrue($relay->connected);
        self::assertTrue($relay->active);
        self::assertNull($relay->endpoint);
        self::assertSame('2026-06-26T10:00:00+00:00', $relay->establishedAt);
        self::assertSame('Connected', $relay->stateLabel());
        self::assertSame('Relay tunnel connected.', $relay->summary());
    }

    public function testMapsAnActiveButNotConnectedStatus(): void
    {
        $relay = RelayStatus::fromArray(['connected' => false, 'active' => true]);

        self::assertFalse($relay->connected);
        self::assertTrue($relay->active);
        self::assertSame('Active (not connected)', $relay->stateLabel());
        self::assertSame('Relay tunnel disconnected.', $relay->summary());
    }

    public function testMapsADisconnectedStatus(): void
    {
        $relay = RelayStatus::fromArray(['connected' => false, 'active' => false]);

        self::assertFalse($relay->connected);
        self::assertFalse($relay->active);
        self::assertSame('Disconnected', $relay->stateLabel());
        self::assertSame('Relay tunnel disconnected.', $relay->summary());
    }

    public function testToleratesAnEmptyPayloadWithDisconnectedDefaults(): void
    {
        $relay = RelayStatus::fromArray([]);

        self::assertFalse($relay->connected);
        self::assertFalse($relay->active);
        self::assertNull($relay->endpoint);
        self::assertNull($relay->establishedAt);
        self::assertSame('Disconnected', $relay->stateLabel());
    }

    public function testCoercesLooseScalarEncodings(): void
    {
        $relay = RelayStatus::fromArray(['connected' => 1, 'active' => '0']);

        self::assertTrue($relay->connected);
        self::assertFalse($relay->active);
        self::assertSame('Connected', $relay->stateLabel());
    }
}

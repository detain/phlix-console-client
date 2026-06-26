<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\DlnaServerStatus;
use PHPUnit\Framework\TestCase;

final class DlnaServerStatusTest extends TestCase
{
    public function testMapsAConfiguredRunningStatus(): void
    {
        $status = DlnaServerStatus::fromArray([
            'enabled' => true,
            'running' => true,
            'serverId' => 'srv-abc',
            'friendlyName' => 'Phlix Living Room',
            'port' => 1900,
            'baseUrl' => 'http://10.0.0.5:1900/',
            'message' => null,
        ]);

        self::assertTrue($status->enabled);
        self::assertTrue($status->running);
        self::assertSame('srv-abc', $status->serverId);
        self::assertSame('Phlix Living Room', $status->friendlyName);
        self::assertSame(1900, $status->port);
        self::assertSame('http://10.0.0.5:1900/', $status->baseUrl);
        self::assertNull($status->message);
        self::assertSame('Running', $status->stateLabel());
    }

    public function testMapsAConfiguredStoppedStatus(): void
    {
        $status = DlnaServerStatus::fromArray([
            'enabled' => true,
            'running' => false,
            'serverId' => 'srv-abc',
            'friendlyName' => 'Phlix',
            'port' => 1900,
            'baseUrl' => 'http://10.0.0.5:1900/',
        ]);

        self::assertTrue($status->enabled);
        self::assertFalse($status->running);
        self::assertSame('Stopped', $status->stateLabel());
        self::assertNull($status->message);
    }

    public function testMapsANotConfiguredStatusWithItsMessage(): void
    {
        $status = DlnaServerStatus::fromArray([
            'enabled' => false,
            'running' => false,
            'serverId' => null,
            'friendlyName' => null,
            'port' => null,
            'baseUrl' => null,
            'message' => 'DLNA server not configured',
        ]);

        self::assertFalse($status->enabled);
        self::assertFalse($status->running);
        self::assertNull($status->serverId);
        self::assertNull($status->friendlyName);
        self::assertNull($status->port);
        self::assertNull($status->baseUrl);
        self::assertSame('DLNA server not configured', $status->message);
        self::assertSame('Not configured', $status->stateLabel());
    }

    public function testToleratesAnEmptyPayloadWithNotConfiguredDefaults(): void
    {
        $status = DlnaServerStatus::fromArray([]);

        self::assertFalse($status->enabled);
        self::assertFalse($status->running);
        self::assertNull($status->serverId);
        self::assertNull($status->friendlyName);
        self::assertNull($status->port);
        self::assertNull($status->baseUrl);
        self::assertNull($status->message);
        self::assertSame('Not configured', $status->stateLabel());
    }

    public function testCoercesLooseScalarEncodings(): void
    {
        // TINYINT/string encodings from the server are coerced to the declared types.
        $status = DlnaServerStatus::fromArray([
            'enabled' => 1,
            'running' => '0',
            'serverId' => 'id',
            'port' => '8200',
        ]);

        self::assertTrue($status->enabled);
        self::assertFalse($status->running);
        self::assertSame(8200, $status->port);
        self::assertSame('Stopped', $status->stateLabel());
    }
}

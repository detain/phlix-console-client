<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\PortForwardStatus;
use PHPUnit\Framework\TestCase;

final class PortForwardStatusTest extends TestCase
{
    public function testMapsAnEnabledStatus(): void
    {
        $pf = PortForwardStatus::fromArray([
            'enabled' => true,
            'method' => 'upnp',
            'externalIp' => '203.0.113.7',
            'externalPort' => 32400,
            'hostname' => 'home.example.com',
        ]);

        self::assertTrue($pf->enabled);
        self::assertSame('upnp', $pf->method);
        self::assertSame('203.0.113.7', $pf->externalIp);
        self::assertSame(32400, $pf->externalPort);
        self::assertSame('home.example.com', $pf->hostname);
        self::assertSame('Enabled', $pf->stateLabel());
        self::assertStringContainsString('home.example.com:32400', $pf->summary());
    }

    public function testMapsADisabledStatus(): void
    {
        $pf = PortForwardStatus::fromArray(['enabled' => false]);

        self::assertFalse($pf->enabled);
        self::assertNull($pf->method);
        self::assertNull($pf->externalPort);
        self::assertSame('Disabled', $pf->stateLabel());
        self::assertSame('Port forwarding disabled.', $pf->summary());
    }

    public function testToleratesAnEmptyPayloadWithDisabledDefaults(): void
    {
        $pf = PortForwardStatus::fromArray([]);

        self::assertFalse($pf->enabled);
        self::assertNull($pf->method);
        self::assertNull($pf->externalIp);
        self::assertNull($pf->externalPort);
        self::assertNull($pf->hostname);
        self::assertSame('Disabled', $pf->stateLabel());
    }

    public function testEnabledSummaryFallsBackWhenEndpointDetailsMissing(): void
    {
        $pf = PortForwardStatus::fromArray(['enabled' => true, 'method' => 'natpmp']);

        self::assertTrue($pf->enabled);
        self::assertSame('Port forwarding enabled.', $pf->summary());
    }

    public function testEnabledSummaryUsesExternalIpWhenNoHostname(): void
    {
        $pf = PortForwardStatus::fromArray([
            'enabled' => '1',
            'externalIp' => '198.51.100.4',
            'externalPort' => '8080',
        ]);

        self::assertTrue($pf->enabled);
        self::assertSame(8080, $pf->externalPort);
        self::assertStringContainsString('198.51.100.4:8080', $pf->summary());
    }
}

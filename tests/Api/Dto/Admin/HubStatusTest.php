<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\HubStatus;
use PHPUnit\Framework\TestCase;

final class HubStatusTest extends TestCase
{
    public function testMapsAPairedStatus(): void
    {
        $hub = HubStatus::fromArray([
            'paired' => true,
            'serverId' => 'srv-1',
            'hubUrl' => 'https://hub.example',
            'enrolledAt' => '2026-06-26T12:00:00+00:00',
            'lastHeartbeat' => null,
        ]);

        self::assertTrue($hub->paired);
        self::assertSame('srv-1', $hub->serverId);
        self::assertSame('https://hub.example', $hub->hubUrl);
        self::assertSame('2026-06-26T12:00:00+00:00', $hub->enrolledAt);
        self::assertNull($hub->lastHeartbeat);
        self::assertSame('Paired', $hub->stateLabel());
        self::assertStringContainsString('hub.example', $hub->summary());
    }

    public function testMapsAnUnpairedStatus(): void
    {
        $hub = HubStatus::fromArray(['paired' => false]);

        self::assertFalse($hub->paired);
        self::assertNull($hub->serverId);
        self::assertNull($hub->hubUrl);
        self::assertSame('Not paired', $hub->stateLabel());
        self::assertSame('Not paired with a hub.', $hub->summary());
    }

    public function testToleratesAnEmptyPayloadWithUnpairedDefaults(): void
    {
        $hub = HubStatus::fromArray([]);

        self::assertFalse($hub->paired);
        self::assertNull($hub->serverId);
        self::assertNull($hub->hubUrl);
        self::assertNull($hub->enrolledAt);
        self::assertNull($hub->lastHeartbeat);
        self::assertSame('Not paired', $hub->stateLabel());
    }

    public function testPairedSummaryFallsBackWhenHubUrlMissing(): void
    {
        $hub = HubStatus::fromArray(['paired' => 1]);

        self::assertTrue($hub->paired);
        self::assertSame('Paired with a hub.', $hub->summary());
    }
}

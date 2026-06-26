<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\SubdomainStatus;
use PHPUnit\Framework\TestCase;

final class SubdomainStatusTest extends TestCase
{
    public function testMapsAClaimedStatus(): void
    {
        $sub = SubdomainStatus::fromArray([
            'claimed' => true,
            'subdomain' => 'myserver',
            'fqdn' => 'myserver.phlix.tv',
            'certPath' => '/etc/ssl/cert.pem',
            'keyPath' => '/etc/ssl/key.pem',
        ]);

        self::assertTrue($sub->claimed);
        self::assertSame('myserver', $sub->subdomain);
        self::assertSame('myserver.phlix.tv', $sub->fqdn);
        self::assertSame('/etc/ssl/cert.pem', $sub->certPath);
        self::assertSame('/etc/ssl/key.pem', $sub->keyPath);
        self::assertSame('Claimed', $sub->stateLabel());
        self::assertStringContainsString('myserver.phlix.tv', $sub->summary());
    }

    public function testMapsAnUnclaimedStatus(): void
    {
        $sub = SubdomainStatus::fromArray(['claimed' => false]);

        self::assertFalse($sub->claimed);
        self::assertNull($sub->subdomain);
        self::assertNull($sub->fqdn);
        self::assertSame('Not claimed', $sub->stateLabel());
        self::assertSame('No subdomain claimed.', $sub->summary());
    }

    public function testToleratesAnEmptyPayloadWithUnclaimedDefaults(): void
    {
        $sub = SubdomainStatus::fromArray([]);

        self::assertFalse($sub->claimed);
        self::assertNull($sub->subdomain);
        self::assertNull($sub->fqdn);
        self::assertNull($sub->certPath);
        self::assertNull($sub->keyPath);
        self::assertSame('Not claimed', $sub->stateLabel());
    }

    public function testClaimedSummaryFallsBackToSubdomainThenGeneric(): void
    {
        $withSub = SubdomainStatus::fromArray(['claimed' => true, 'subdomain' => 'myserver']);
        self::assertStringContainsString('myserver', $withSub->summary());

        $bare = SubdomainStatus::fromArray(['claimed' => true]);
        self::assertSame('Claimed a subdomain.', $bare->summary());
    }
}

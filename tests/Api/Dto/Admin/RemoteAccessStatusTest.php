<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\HubStatus;
use Phlix\Console\Api\Dto\Admin\PortForwardStatus;
use Phlix\Console\Api\Dto\Admin\RelayStatus;
use Phlix\Console\Api\Dto\Admin\RemoteAccessStatus;
use Phlix\Console\Api\Dto\Admin\SubdomainStatus;
use PHPUnit\Framework\TestCase;

final class RemoteAccessStatusTest extends TestCase
{
    public function testFromPartsHoldsTheFourSubDtos(): void
    {
        $hub = HubStatus::fromArray(['paired' => true, 'hubUrl' => 'https://hub']);
        $sub = SubdomainStatus::fromArray(['claimed' => true, 'fqdn' => 'a.phlix.tv']);
        $relay = RelayStatus::fromArray(['connected' => true, 'active' => true]);
        $pf = PortForwardStatus::fromArray(['enabled' => true, 'method' => 'upnp']);

        $status = RemoteAccessStatus::fromParts($hub, $sub, $relay, $pf);

        self::assertSame($hub, $status->hub);
        self::assertSame($sub, $status->subdomain);
        self::assertSame($relay, $status->relay);
        self::assertSame($pf, $status->portForward);
        self::assertTrue($status->hub->paired);
        self::assertTrue($status->subdomain->claimed);
        self::assertTrue($status->relay->connected);
        self::assertTrue($status->portForward->enabled);
    }
}

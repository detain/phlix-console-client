<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\PortForwardCandidate;
use PHPUnit\Framework\TestCase;

final class PortForwardCandidateTest extends TestCase
{
    public function testMapsAFullRow(): void
    {
        $candidate = PortForwardCandidate::fromArray([
            'hostname' => 'http://192.168.1.100:32400',
            'externalIp' => '203.0.113.7',
            'port' => 32400,
        ]);

        self::assertSame('http://192.168.1.100:32400', $candidate->hostname);
        self::assertSame('203.0.113.7', $candidate->externalIp);
        self::assertSame(32400, $candidate->port);
    }

    public function testToleratesMissingKeysWithDefaults(): void
    {
        $candidate = PortForwardCandidate::fromArray([]);

        self::assertSame('', $candidate->hostname);
        self::assertSame('', $candidate->externalIp);
        self::assertSame(0, $candidate->port);
    }

    public function testCoercesANumericStringPort(): void
    {
        $candidate = PortForwardCandidate::fromArray([
            'hostname' => 'http://10.0.0.5:8096',
            'externalIp' => '198.51.100.4',
            'port' => '8096',
        ]);

        self::assertSame(8096, $candidate->port);
    }

    public function testToleratesNonScalarValues(): void
    {
        $candidate = PortForwardCandidate::fromArray([
            'hostname' => ['nope'],
            'externalIp' => null,
            'port' => 'not-a-number',
        ]);

        self::assertSame('', $candidate->hostname);
        self::assertSame('', $candidate->externalIp);
        self::assertSame(0, $candidate->port);
    }
}

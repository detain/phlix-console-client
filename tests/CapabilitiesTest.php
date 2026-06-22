<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use Phlix\Console\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    public function testDetectReturnsAllKeys(): void
    {
        $d = (new Capabilities())->detect();

        foreach (['protocol', 'sixel', 'kitty', 'iterm2', 'halfblock', 'colorProfile', 'term', 'tmux'] as $key) {
            self::assertArrayHasKey($key, $d);
        }
        self::assertIsString($d['protocol']);
        self::assertNotSame('', $d['protocol']);
        self::assertIsBool($d['halfblock']);
    }

    public function testReportMentionsProtocolAndProtocolList(): void
    {
        $report = (new Capabilities())->report();

        self::assertStringContainsString('Render protocol', $report);
        self::assertStringContainsString('Supported image protocols', $report);
        self::assertStringContainsString('halfblock', $report);
    }
}

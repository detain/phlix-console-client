<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\Channel;
use PHPUnit\Framework\TestCase;

final class ChannelTest extends TestCase
{
    public function testFromArrayMapsAFullRow(): void
    {
        $c = Channel::fromArray([
            'id' => 'c-1',
            'channel_id' => 'wxyz.1',
            'name' => 'WXYZ HD',
            'number' => 7,
            'callsign' => 'WXYZ',
            'type' => 'hdhomerun',
            'description' => 'Local affiliate',
            'icon_url' => 'http://x/logo.png',
            'visibility' => 'visible',
            'enabled' => 1,
        ]);

        self::assertSame('c-1', $c->id);
        self::assertSame('wxyz.1', $c->channelId);
        self::assertSame('WXYZ HD', $c->name);
        self::assertSame(7, $c->number);
        self::assertSame('WXYZ', $c->callsign);
        self::assertSame('hdhomerun', $c->type);
        self::assertTrue($c->enabled);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $c = Channel::fromArray([]);

        self::assertSame('', $c->id);
        self::assertSame('', $c->channelId);
        self::assertSame('', $c->name);
        self::assertSame(0, $c->number);
        self::assertNull($c->callsign);
        self::assertSame('', $c->type);
        // With no visibility ('visible' default) and no explicit enabled flag, the
        // channel is enabled.
        self::assertTrue($c->enabled);
    }

    public function testHiddenVisibilityDisablesEvenWhenEnabledFlagIsTrue(): void
    {
        $c = Channel::fromArray(['visibility' => 'hidden', 'enabled' => 1]);

        self::assertFalse($c->enabled, 'hidden visibility wins over an enabled flag');
    }

    public function testExplicitEnabledZeroDisablesEvenWhenVisible(): void
    {
        $c = Channel::fromArray(['visibility' => 'visible', 'enabled' => 0]);

        self::assertFalse($c->enabled);
    }

    public function testVisibleAndEnabledIsEnabled(): void
    {
        $c = Channel::fromArray(['visibility' => 'visible', 'enabled' => 1]);

        self::assertTrue($c->enabled);
    }

    public function testNumberCoercesNumericString(): void
    {
        self::assertSame(13, Channel::fromArray(['number' => '13'])->number);
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\ServerSetting;
use PHPUnit\Framework\TestCase;

final class ServerSettingTest extends TestCase
{
    public function testRendersABoolValue(): void
    {
        $on = ServerSetting::fromParts('feature', true, 'bool', true);
        self::assertSame('true', $on->displayValue);
        self::assertTrue($on->isBool());
        self::assertTrue($on->boolValue());
        self::assertTrue($on->overridden);

        $off = ServerSetting::fromParts('feature', false, 'bool', false);
        self::assertSame('false', $off->displayValue);
        self::assertFalse($off->boolValue());
        self::assertFalse($off->overridden);
    }

    public function testRendersAnIntValue(): void
    {
        $setting = ServerSetting::fromParts('port', 8096, 'int', false);

        self::assertSame('port', $setting->key);
        self::assertSame('8096', $setting->displayValue);
        self::assertSame('int', $setting->type);
        self::assertFalse($setting->isBool());
    }

    public function testRendersAFloatValue(): void
    {
        $setting = ServerSetting::fromParts('ratio', 1.5, 'float', false);

        self::assertSame('1.5', $setting->displayValue);
    }

    public function testRendersAStringValue(): void
    {
        $setting = ServerSetting::fromParts('name', 'Phlix', 'string', false);

        self::assertSame('Phlix', $setting->displayValue);
    }

    public function testRendersAJsonArrayValueCompactly(): void
    {
        $setting = ServerSetting::fromParts('hosts', ['a', 'b'], 'json', true);

        self::assertSame('["a","b"]', $setting->displayValue);
    }

    public function testRendersAJsonObjectValueCompactly(): void
    {
        $setting = ServerSetting::fromParts('opts', ['x' => 1], 'json', false);

        self::assertSame('{"x":1}', $setting->displayValue);
    }

    public function testRendersNullAsEmpty(): void
    {
        $setting = ServerSetting::fromParts('missing', null, 'string', false);

        self::assertSame('', $setting->displayValue);
    }

    public function testBoolValueIsFalseWhenNotTheLiteralTrue(): void
    {
        // A non-bool display value never reads as a true bool.
        $setting = ServerSetting::fromParts('weird', '1', 'bool', false);

        self::assertSame('1', $setting->displayValue);
        self::assertFalse($setting->boolValue());
    }
}

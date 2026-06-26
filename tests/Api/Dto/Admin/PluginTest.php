<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function testMapsAFullInstalledRow(): void
    {
        $plugin = Plugin::fromArray([
            'id' => 'p-1',
            'name' => 'trakt',
            'version' => '1.2.3',
            'type' => 'scrobbler',
            'entry' => 'Plugin.php',
            'enabled' => true,
            'installed_at' => '2026-06-26T12:00:00-04:00',
            'signed' => true,
            'settings' => ['api_key' => '***'],
        ]);

        self::assertSame('trakt', $plugin->name);
        self::assertSame('1.2.3', $plugin->version);
        self::assertSame('scrobbler', $plugin->type);
        self::assertTrue($plugin->enabled);
        self::assertSame('2026-06-26T12:00:00-04:00', $plugin->installedAt);
        self::assertTrue($plugin->signed);
    }

    public function testToleratesMissingKeysWithDefaults(): void
    {
        $plugin = Plugin::fromArray([]);

        self::assertSame('', $plugin->name);
        self::assertSame('', $plugin->version);
        self::assertSame('', $plugin->type);
        self::assertFalse($plugin->enabled);
        self::assertNull($plugin->installedAt);
        self::assertFalse($plugin->signed);
    }

    public function testCoercesTinyintBooleans(): void
    {
        $enabled = Plugin::fromArray(['enabled' => 1, 'signed' => 0]);
        self::assertTrue($enabled->enabled);
        self::assertFalse($enabled->signed);

        $disabled = Plugin::fromArray(['enabled' => '0', 'signed' => '1']);
        self::assertFalse($disabled->enabled);
        self::assertTrue($disabled->signed);
    }

    public function testMapsAThinEnableDisableShape(): void
    {
        // The enable/disable endpoints return only `{name, enabled}`.
        $plugin = Plugin::fromArray(['name' => 'trakt', 'enabled' => false]);

        self::assertSame('trakt', $plugin->name);
        self::assertFalse($plugin->enabled);
        self::assertSame('', $plugin->version);
        self::assertNull($plugin->installedAt);
    }
}

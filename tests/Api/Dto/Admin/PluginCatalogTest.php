<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\CatalogPlugin;
use Phlix\Console\Api\Dto\Admin\PluginCatalog;
use PHPUnit\Framework\TestCase;

final class PluginCatalogTest extends TestCase
{
    public function testBuildsTheNestedPluginList(): void
    {
        $catalog = PluginCatalog::fromArray([
            'source' => 'https://example.com/catalog.json',
            'name' => 'Official',
            'plugins' => [
                ['name' => 'trakt', 'title' => 'Trakt'],
                ['name' => 'lastfm', 'title' => 'Last.fm'],
            ],
        ]);

        self::assertSame('https://example.com/catalog.json', $catalog->source);
        self::assertSame('Official', $catalog->name);
        self::assertContainsOnlyInstancesOf(CatalogPlugin::class, $catalog->plugins);
        self::assertCount(2, $catalog->plugins);
        self::assertSame('trakt', $catalog->plugins[0]->name);
    }

    public function testDefaultsAndTolerance(): void
    {
        $catalog = PluginCatalog::fromArray([]);

        self::assertSame('', $catalog->source);
        self::assertSame('', $catalog->name);
        self::assertSame([], $catalog->plugins);
    }

    public function testToleratesANonArrayPluginsPayload(): void
    {
        $catalog = PluginCatalog::fromArray(['plugins' => 'nope']);

        self::assertSame([], $catalog->plugins);
    }

    public function testSkipsNonArrayPluginRows(): void
    {
        $catalog = PluginCatalog::fromArray([
            'plugins' => [['name' => 'trakt'], 'nope', 7],
        ]);

        self::assertCount(1, $catalog->plugins);
        self::assertSame('trakt', $catalog->plugins[0]->name);
    }
}

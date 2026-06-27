<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\CatalogPlugin;
use Phlix\Console\Api\Dto\Admin\PluginCatalog;
use Phlix\Console\Api\Dto\Admin\PluginCatalogResult;
use PHPUnit\Framework\TestCase;

final class PluginCatalogResultTest extends TestCase
{
    public function testBuildsTheWholeNestedResult(): void
    {
        $result = PluginCatalogResult::fromArray([
            'default_source' => 'https://a.com/catalog.json',
            'sources' => ['https://a.com/catalog.json', 'https://b.com/catalog.json'],
            'catalogs' => [
                ['source' => 'https://a.com/catalog.json', 'name' => 'A', 'plugins' => [['name' => 'trakt']]],
                ['source' => 'https://b.com/catalog.json', 'name' => 'B', 'plugins' => [['name' => 'lastfm'], ['name' => 'imdb']]],
            ],
            'errors' => ['https://b.com failed'],
        ]);

        self::assertSame('https://a.com/catalog.json', $result->defaultSource);
        self::assertSame(['https://a.com/catalog.json', 'https://b.com/catalog.json'], $result->sources);
        self::assertContainsOnlyInstancesOf(PluginCatalog::class, $result->catalogs);
        self::assertCount(2, $result->catalogs);
        self::assertSame(['https://b.com failed'], $result->errors);
    }

    public function testDefaultsEveryMissingKey(): void
    {
        $result = PluginCatalogResult::fromArray([]);

        self::assertSame('', $result->defaultSource);
        self::assertSame([], $result->sources);
        self::assertSame([], $result->catalogs);
        self::assertSame([], $result->errors);
    }

    public function testSkipsNonArrayCatalogRows(): void
    {
        $result = PluginCatalogResult::fromArray([
            'catalogs' => [['source' => 'a', 'plugins' => []], 'nope', 7],
        ]);

        self::assertCount(1, $result->catalogs);
        self::assertSame('a', $result->catalogs[0]->source);
    }

    public function testToleratesANonArrayCatalogsPayload(): void
    {
        $result = PluginCatalogResult::fromArray(['catalogs' => 'nope']);

        self::assertSame([], $result->catalogs);
    }

    public function testFlatPluginsFlattensEveryCatalogInOrder(): void
    {
        $result = PluginCatalogResult::fromArray([
            'catalogs' => [
                ['source' => 'a', 'plugins' => [['name' => 'trakt']]],
                ['source' => 'b', 'plugins' => [['name' => 'lastfm'], ['name' => 'imdb']]],
            ],
        ]);

        $flat = $result->flatPlugins();

        self::assertContainsOnlyInstancesOf(CatalogPlugin::class, $flat);
        self::assertCount(3, $flat);
        self::assertSame(['trakt', 'lastfm', 'imdb'], array_map(static fn (CatalogPlugin $p): string => $p->name, $flat));
    }

    public function testFlatPluginsIsEmptyWhenNoCatalogs(): void
    {
        $result = PluginCatalogResult::fromArray([]);

        self::assertSame([], $result->flatPlugins());
    }
}

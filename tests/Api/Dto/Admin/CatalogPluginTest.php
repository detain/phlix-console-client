<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\CatalogPlugin;
use PHPUnit\Framework\TestCase;

final class CatalogPluginTest extends TestCase
{
    public function testMapsTheFullLiveEntryShape(): void
    {
        $plugin = CatalogPlugin::fromArray([
            'name' => 'trakt',
            'title' => 'Trakt Scrobbler',
            'type' => 'scrobbler',
            'summary' => 'Sync your watches',
            'description' => 'A longer description.',
            'repo' => 'https://github.com/owner/trakt',
            'author' => 'Owner',
            'tags' => ['scrobbler', 'sync'],
            'installed' => true,
            'enabled' => false,
        ]);

        self::assertSame('trakt', $plugin->name);
        self::assertSame('Trakt Scrobbler', $plugin->title);
        self::assertSame('scrobbler', $plugin->type);
        self::assertSame('Sync your watches', $plugin->summary);
        self::assertSame('A longer description.', $plugin->description);
        self::assertSame('https://github.com/owner/trakt', $plugin->repo);
        self::assertSame('Owner', $plugin->author);
        self::assertSame(['scrobbler', 'sync'], $plugin->tags);
        self::assertTrue($plugin->installed);
        self::assertFalse($plugin->enabled);
    }

    public function testDefaultsEveryMissingKey(): void
    {
        $plugin = CatalogPlugin::fromArray([]);

        self::assertSame('', $plugin->name);
        self::assertSame('', $plugin->title);
        self::assertSame('', $plugin->type);
        self::assertSame('', $plugin->summary);
        self::assertSame('', $plugin->description);
        self::assertSame('', $plugin->repo);
        self::assertSame('', $plugin->author);
        self::assertSame([], $plugin->tags);
        self::assertFalse($plugin->installed);
        self::assertFalse($plugin->enabled);
    }

    public function testCoercesTagsAndDropsNonStrings(): void
    {
        $plugin = CatalogPlugin::fromArray([
            'tags' => ['ok', '', null, ['nested'], 7],
        ]);

        self::assertSame(['ok', '7'], $plugin->tags);
    }

    public function testTagsToleratesANonArray(): void
    {
        $plugin = CatalogPlugin::fromArray(['tags' => 'nope']);

        self::assertSame([], $plugin->tags);
    }

    public function testCoercesTinyintInstalledAndEnabled(): void
    {
        $plugin = CatalogPlugin::fromArray(['installed' => 1, 'enabled' => 0]);

        self::assertTrue($plugin->installed);
        self::assertFalse($plugin->enabled);
    }

    public function testDisplayTitlePrefersTheTitle(): void
    {
        $plugin = CatalogPlugin::fromArray(['name' => 'trakt', 'title' => 'Trakt']);

        self::assertSame('Trakt', $plugin->displayTitle());
    }

    public function testDisplayTitleFallsBackToTheName(): void
    {
        $plugin = CatalogPlugin::fromArray(['name' => 'trakt', 'title' => '']);

        self::assertSame('trakt', $plugin->displayTitle());
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Msg\CollectionsFailedMsg;
use Phlix\Console\Msg\CollectionsLoadedMsg;
use Phlix\Console\Screen\CollectionsScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\KeyType;

/**
 * Tests for CollectionsScreen.
 */
final class CollectionsScreenTest extends TestCase
{
    private function createScreenWithItems(array $items): CollectionsScreen
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new CollectionsScreen($api, 80, 24);

        // Inject loaded items via reflection
        $reflection = new \ReflectionClass($screen);
        $itemsProperty = $reflection->getProperty('items');
        $itemsProperty->setAccessible(true);
        $itemsProperty->setValue($screen, $items);

        $loadingProperty = $reflection->getProperty('loading');
        $loadingProperty->setAccessible(true);
        $loadingProperty->setValue($screen, false);

        return $screen;
    }

    public function testScreenConstructs(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new CollectionsScreen($api, 80, 24);

        $this->assertInstanceOf(CollectionsScreen::class, $screen);
        $this->assertSame([], $screen->items());
        $this->assertSame(0, $screen->selectedIndex());
        $this->assertTrue($screen->isLoading());
        $this->assertNull($screen->error());
    }

    public function testScreenLoads(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new CollectionsScreen($api, 80, 24);

        $items = [
            MediaItem::fromArray([
                'id' => 'c1',
                'name' => 'My Collection',
                'type' => 'collection',
            ]),
        ];

        $msg = new CollectionsLoadedMsg($items);
        [$nextScreen, $continuation] = $screen->update($msg);

        $this->assertFalse($nextScreen->isLoading());
        $this->assertNull($nextScreen->error());
        $this->assertCount(1, $nextScreen->items());
        $this->assertSame('My Collection', $nextScreen->items()[0]->name);
    }

    public function testScreenRendersRows(): void
    {
        $items = [
            MediaItem::fromArray([
                'id' => 'c1',
                'name' => 'Collection One',
                'type' => 'collection',
            ]),
            MediaItem::fromArray([
                'id' => 'c2',
                'name' => 'Collection Two',
                'type' => 'collection',
            ]),
        ];

        $screen = $this->createScreenWithItems($items);
        $view = $screen->view();

        $this->assertStringContainsString('Collection One', $view);
        $this->assertStringContainsString('Collection Two', $view);
        $this->assertStringContainsString('▶', $view); // selected row indicator
    }

    public function testScreenHandlesEmptyCollections(): void
    {
        $screen = $this->createScreenWithItems([]);
        $view = $screen->view();

        $this->assertStringContainsString('No collections yet', $view);
    }

    public function testScreenHandlesLoadFailed(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new CollectionsScreen($api, 80, 24);

        // Force loading to false so we can see the error state
        $reflection = new \ReflectionClass($screen);
        $loadingProperty = $reflection->getProperty('loading');
        $loadingProperty->setAccessible(true);
        $loadingProperty->setValue($screen, false);

        $msg = new CollectionsFailedMsg('Could not load collections.');
        [$nextScreen] = $screen->update($msg);

        $this->assertFalse($nextScreen->isLoading());
        $this->assertSame('Could not load collections.', $nextScreen->error());
    }

    public function testScreenNavigation(): void
    {
        $items = [
            MediaItem::fromArray([
                'id' => 'c1',
                'name' => 'Collection One',
                'type' => 'collection',
            ]),
            MediaItem::fromArray([
                'id' => 'c2',
                'name' => 'Collection Two',
                'type' => 'collection',
            ]),
        ];

        $screen = $this->createScreenWithItems($items);

        // Press 'j' (vi-style down) to move selection
        $downKey = new KeyMsg(KeyType::Char, 'j');
        [$nextScreen] = $screen->update($downKey);

        $this->assertSame(1, $nextScreen->selectedIndex());

        // Press 'k' (vi-style up) to move back
        $upKey = new KeyMsg(KeyType::Char, 'k');
        [$nextNextScreen] = $nextScreen->update($upKey);

        $this->assertSame(0, $nextNextScreen->selectedIndex());
    }

    public function testScreenResize(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new CollectionsScreen($api, 80, 24);

        $msg = new WindowSizeMsg(120, 40);
        // Verify resize message is handled without throwing
        [$nextScreen, $continuation] = $screen->update($msg);

        $this->assertInstanceOf(CollectionsScreen::class, $nextScreen);
        $this->assertNull($continuation);
    }

    public function testBreadcrumbLabel(): void
    {
        $screen = $this->createScreenWithItems([]);
        $this->assertSame('Collections', $screen->crumbLabel());
    }

    public function testLoadingViewBeforeData(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new CollectionsScreen($api, 80, 24);

        $view = $screen->view();

        $this->assertStringContainsString('Loading', $view);
        $this->assertStringContainsString('Collections', $view);
    }
}

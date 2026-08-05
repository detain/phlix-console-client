<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\RecentlyWatchedItem;
use Phlix\Console\Msg\WatchHistoryLoadedMsg;
use Phlix\Console\Msg\WatchHistoryFailedMsg;
use Phlix\Console\Screen\WatchHistoryScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\KeyType;

/**
 * Tests for WatchHistoryScreen.
 */
final class WatchHistoryScreenTest extends TestCase
{
    private function createScreenWithItems(array $items): WatchHistoryScreen
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new WatchHistoryScreen($api, 80, 24);

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
        $screen = new WatchHistoryScreen($api, 80, 24);

        $this->assertInstanceOf(WatchHistoryScreen::class, $screen);
        $this->assertSame([], $screen->items());
        $this->assertSame(0, $screen->selectedIndex());
        $this->assertTrue($screen->isLoading());
        $this->assertNull($screen->error());
    }

    public function testScreenLoads(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new WatchHistoryScreen($api, 80, 24);

        $items = [
            RecentlyWatchedItem::fromArray([
                'media_item_id' => 'm1',
                'name' => 'Test Movie',
                'progress' => 0.75,
                'playback_status' => 'completed',
                'updated_at' => time(),
            ]),
        ];

        $msg = new WatchHistoryLoadedMsg($items);
        [$nextScreen, $continuation] = $screen->update($msg);

        $this->assertFalse($nextScreen->isLoading());
        $this->assertNull($nextScreen->error());
        $this->assertCount(1, $nextScreen->items());
        $this->assertSame('Test Movie', $nextScreen->items()[0]->name);
    }

    public function testScreenRendersRows(): void
    {
        $items = [
            RecentlyWatchedItem::fromArray([
                'media_item_id' => 'm1',
                'name' => 'Movie One',
                'progress' => 0.50,
                'playback_status' => 'playing',
                'updated_at' => time(),
            ]),
            RecentlyWatchedItem::fromArray([
                'media_item_id' => 'm2',
                'name' => 'Movie Two',
                'progress' => 1.00,
                'playback_status' => 'completed',
                'updated_at' => time(),
            ]),
        ];

        $screen = $this->createScreenWithItems($items);
        $view = $screen->view();

        $this->assertStringContainsString('Movie One', $view);
        $this->assertStringContainsString('Movie Two', $view);
        $this->assertStringContainsString('▶', $view); // selected row indicator
    }

    public function testScreenHandlesEmptyHistory(): void
    {
        $screen = $this->createScreenWithItems([]);
        $view = $screen->view();

        $this->assertStringContainsString('No watch history yet', $view);
        $this->assertStringContainsString('Start watching to build your history', $view);
    }

    public function testScreenHandlesLoadFailed(): void
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $screen = new WatchHistoryScreen($api, 80, 24);

        // Force loading to false so we can see the error state
        $reflection = new \ReflectionClass($screen);
        $loadingProperty = $reflection->getProperty('loading');
        $loadingProperty->setAccessible(true);
        $loadingProperty->setValue($screen, false);

        $msg = new WatchHistoryFailedMsg('Could not load watch history.');
        [$nextScreen] = $screen->update($msg);

        $this->assertFalse($nextScreen->isLoading());
        $this->assertSame('Could not load watch history.', $nextScreen->error());
    }

    public function testScreenNavigation(): void
    {
        $items = [
            RecentlyWatchedItem::fromArray([
                'media_item_id' => 'm1',
                'name' => 'Movie One',
                'progress' => 0.5,
                'playback_status' => 'playing',
                'updated_at' => time(),
            ]),
            RecentlyWatchedItem::fromArray([
                'media_item_id' => 'm2',
                'name' => 'Movie Two',
                'progress' => 0.3,
                'playback_status' => 'paused',
                'updated_at' => time(),
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
        $screen = new WatchHistoryScreen($api, 80, 24);

        $msg = new WindowSizeMsg(120, 40);
        // Verify resize message is handled without throwing
        [$nextScreen, $continuation] = $screen->update($msg);

        $this->assertInstanceOf(WatchHistoryScreen::class, $nextScreen);
        $this->assertNull($continuation);
    }
}

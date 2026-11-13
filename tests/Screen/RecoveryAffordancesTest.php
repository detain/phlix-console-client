<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Screen\FavoritesScreen;
use Phlix\Console\Screen\PlaylistsScreen;
use Phlix\Console\Screen\RecommendationsScreen;
use Phlix\Console\Screen\WatchHistoryScreen;
use Phlix\Console\Store\FavoritesStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * CON-44 / CON-78 tripwire: a hint row published over a failed load names a
 * key the handler really arms. Each failed-load frame says "r  retry" and
 * pressing 'r' clears the error and returns a re-fetch command; the favorites
 * empty state tells the truth about where F works.
 */
final class RecoveryAffordancesTest extends TestCase
{
    private function api(): ApiClient
    {
        return new ApiClient('https://srv', new FakeTransport());
    }

    /** @return iterable<string, array{object}> */
    public static function failedScreens(): iterable
    {
        $api = new ApiClient('https://srv', new FakeTransport());

        yield 'favorites' => [(new FavoritesScreen(new FavoritesStore($api)))->withError('boom')];
        yield 'history' => [(new WatchHistoryScreen($api))->withError('boom')];
        yield 'playlists' => [(new PlaylistsScreen($api))->withError('boom')];
        yield 'recommendations' => [(new RecommendationsScreen($api))->withError('boom')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failedScreens')]
    public function testErrorHintNamesArmedRetryKey(object $screen): void
    {
        $this->assertStringContainsString('r  retry', $screen->view());

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        $this->assertNotNull($cmd, "'r' over a failed load must re-fetch");
        $this->assertNull($next->error());
    }

    public function testFavoritesEmptyStateNamesWhereFWorks(): void
    {
        $screen = new FavoritesScreen(new FavoritesStore($this->api()));
        [$loaded] = $screen->update(new \Phlix\Console\Msg\FavoritesLoadedMsg([]));

        $this->assertStringContainsString('Open an item and press F', $loaded->view());
    }
}

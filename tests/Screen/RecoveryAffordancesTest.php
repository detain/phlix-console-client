<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\BooksFailedMsg;
use Phlix\Console\Msg\FederationSharesFailedMsg;
use Phlix\Console\Msg\LibrariesFailedMsg;
use Phlix\Console\Msg\MusicFailedMsg;
use Phlix\Console\Msg\SharedWithMeFailedMsg;
use Phlix\Console\Screen\BooksScreen;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Screen\FederationSharesScreen;
use Phlix\Console\Screen\MusicScreen;
use Phlix\Console\Screen\SharedWithMeScreen;
use Phlix\Console\Store\BooksStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Store\MusicStore;
use SugarCraft\Image\Mosaic;
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

    /**
     * The other five screens that publish a retry/refresh hint over a failed
     * load [CON-S28]: each is driven into the error state through its real
     * failure Msg, its frame names the key, and the key re-fetches.
     *
     * @return iterable<string, array{object, string, string}>
     */
    public static function failedByMessage(): iterable
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $hub = new HubClient($api);
        $boom = 'boom';

        [$fed] = (new FederationSharesScreen($hub))->update(new FederationSharesFailedMsg($boom));
        yield 'federation shares' => [$fed, 'r  retry', 'r'];

        [$shared] = (new SharedWithMeScreen($hub))->update(new SharedWithMeFailedMsg($boom));
        yield 'shared with me' => [$shared, 'r  retry', 'r'];

        [$music] = (new MusicScreen(new MusicStore($api)))->update(new MusicFailedMsg($boom));
        yield 'music' => [$music, 'r  retry', 'r'];

        [$books] = (new BooksScreen(
            new BooksStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            'https://srv',
            'lib1',
            'Books',
            0,
        ))->update(new BooksFailedMsg($boom));
        yield 'books' => [$books, 'r  retry', 'r'];

        $browse = new BrowseScreen(
            AuthUser::fromArray(['id' => 'u', 'username' => 'joe']),
            new LibrariesStore($api),
            new MediaStore($api),
            new FavoritesStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            'https://srv',
        );
        [$browse] = $browse->update(new LibrariesFailedMsg($boom));
        yield 'browse' => [$browse, 'R  refresh', 'R'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failedByMessage')]
    public function testMessageDrivenErrorHintNamesArmedKey(object $screen, string $hint, string $key): void
    {
        $this->assertStringContainsString($hint, $screen->view());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, $key));

        $this->assertNotNull($cmd, "'{$key}' over a failed load must re-fetch");
    }

    public function testFavoritesEmptyStateNamesWhereFWorks(): void
    {
        $screen = new FavoritesScreen(new FavoritesStore($this->api()));
        [$loaded] = $screen->update(new \Phlix\Console\Msg\FavoritesLoadedMsg([]));

        $this->assertStringContainsString('Open an item and press F', $loaded->view());
    }
}

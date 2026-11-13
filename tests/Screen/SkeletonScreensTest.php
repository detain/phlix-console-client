<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Screen\AdminAuthProvidersScreen;
use Phlix\Console\Screen\AdminDuplicatesScreen;
use Phlix\Console\Screen\AdminWatchHistoryScreen;
use Phlix\Console\Screen\AdminWebhooksScreen;
use Phlix\Console\Screen\FavoritesScreen;
use Phlix\Console\Screen\FederationSharesScreen;
use Phlix\Console\Screen\InviteLinksScreen;
use Phlix\Console\Screen\PlaylistsScreen;
use Phlix\Console\Screen\RecommendationsScreen;
use Phlix\Console\Screen\Shimmering;
use Phlix\Console\Screen\SharedWithMeScreen;
use Phlix\Console\Screen\WatchHistoryScreen;
use Phlix\Console\Store\FavoritesStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Model;
use SugarCraft\Core\Util\Ansi;

/**
 * [CON-S11] the eleven screens that swapped their bare "Loading…" line for the
 * animated skeleton each render it while loading AND report isLoading() — the
 * App's shimmer ticker keys off both. A fresh screen (pre-data) is loading.
 */
final class SkeletonScreensTest extends TestCase
{
    /** @return array<string, array{\Closure(): Model}> */
    public static function screens(): array
    {
        $api = static fn (): ApiClient => new ApiClient('https://srv', new FakeTransport());

        return [
            'favorites' => [static fn (): Model => new FavoritesScreen(new FavoritesStore($api()), 80, 24)],
            'watch history' => [static fn (): Model => new WatchHistoryScreen($api(), 80, 24)],
            'playlists' => [static fn (): Model => new PlaylistsScreen($api(), 80, 24)],
            'shared with me' => [static fn (): Model => new SharedWithMeScreen(new HubClient($api()), 80, 24)],
            'federation shares' => [static fn (): Model => new FederationSharesScreen(new HubClient($api()), 80, 24)],
            'invite links' => [static fn (): Model => new InviteLinksScreen(new HubClient($api()), 80, 24)],
            'recommendations' => [static fn (): Model => new RecommendationsScreen($api(), 80, 24)],
            'admin watch history' => [static fn (): Model => new AdminWatchHistoryScreen(new AdminClient($api()), 80, 24)],
            'admin webhooks' => [static fn (): Model => new AdminWebhooksScreen(new AdminClient($api()), 80, 24)],
            'admin duplicates' => [static fn (): Model => new AdminDuplicatesScreen(new AdminClient($api()), 'lib1', 80, 24)],
            'admin auth providers' => [static fn (): Model => new AdminAuthProvidersScreen(new AdminClient($api()), 80, 24)],
        ];
    }

    /** @param \Closure(): Model $make */
    #[DataProvider('screens')]
    public function testLoadingScreenRendersTheSkeletonAndReportsLoading(\Closure $make): void
    {
        $screen = $make();

        self::assertInstanceOf(Shimmering::class, $screen, 'the App can drive its shimmer phase');
        self::assertTrue($screen->isLoading(), 'a screen with no data yet reports isLoading()');

        $view = Ansi::strip($screen->view());
        self::assertMatchesRegularExpression('/[░▒▓]{8,}/u', $view, 'the loading body carries the skeleton bars');
    }
}

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\AdminUser;
use Phlix\Console\Api\Dto\Admin\WatchHistoryEntry;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminUsersLoadedMsg;
use Phlix\Console\Msg\AdminWatchHistoryLoadedMsg;
use Phlix\Console\Msg\MusicRangeLoadedMsg;
use Phlix\Console\Screen\AdminUsersScreen;
use Phlix\Console\Screen\AdminWatchHistoryScreen;
use Phlix\Console\Screen\CollectionsScreen;
use Phlix\Console\Screen\Loadable;
use Phlix\Console\Screen\MusicScreen;
use Phlix\Console\Screen\Shimmering;
use Phlix\Console\Store\MusicRange;
use Phlix\Console\Store\MusicStore;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Ui\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * M-CONSOLE-75 + M-CONSOLE-76 [review console__uxdesign]: windowed tables show
 * the cursor's position (the LibraryScreen `· i/N` idiom) on a line they
 * already render, and the three list screens that still showed a static
 * one-line loader shimmer like the grid screens — the App's gated tick drives
 * any top screen that is both Loadable and Shimmering.
 */
final class WindowedListAffordancesTest extends TestCase
{
    // ---- M-CONSOLE-75: the indicator helper ------------------------------

    /**
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function providePositions(): array
    {
        return [
            'empty list' => [0, 0, ''],
            'single row' => [1, 0, ''],
            'first of five' => [5, 0, ' · 1/5'],
            'middle of five' => [5, 2, ' · 3/5'],
            'last of five' => [5, 4, ' · 5/5'],
            'above the list clamps in' => [5, 9, ' · 5/5'],
            'below the list clamps in' => [5, -2, ' · 1/5'],
        ];
    }

    #[DataProvider('providePositions')]
    public function testThePositionIndicatorStatesTheTailOrItsAbsence(int $count, int $selected, string $expected): void
    {
        self::assertSame($expected, Table::positionIndicator($count, $selected));
    }

    public function testTheAdminUsersHeaderCarriesThePositionTail(): void
    {
        $screen = $this->loadedAdminUsers();

        self::assertStringContainsString('(2 users) · 1/2', $screen->view());

        [$moved] = $screen->update(new KeyMsg(KeyType::Down));

        self::assertStringContainsString('(2 users) · 2/2', $moved->view());
    }

    public function testTheMusicHintCarriesThePositionTail(): void
    {
        $screen = $this->loadedMusic();

        $view = $screen->view();

        self::assertStringContainsString('· 1/2', $view, 'the windowed table states the cursor position');
        self::assertStringNotContainsString('· 1/1', $view);
    }

    public function testTheWatchHistoryHintCarriesThePositionTail(): void
    {
        $screen = new AdminWatchHistoryScreen($this->adminClient(), cols: 90, rows: 20);
        [$screen] = $screen->update(new AdminWatchHistoryLoadedMsg([
            $this->entry('h1'),
            $this->entry('h2'),
        ]));

        [$moved] = $screen->update(new KeyMsg(KeyType::Down));

        self::assertStringContainsString('· 2/2', $moved->view());
    }

    public function testTheSingleEntryListsOmitTheTail(): void
    {
        $screen = new AdminWatchHistoryScreen($this->adminClient(), cols: 90, rows: 20);
        [$screen] = $screen->update(new AdminWatchHistoryLoadedMsg([$this->entry('h1')]));

        self::assertStringNotContainsString(' · 1/1', $screen->view());
    }

    /**
     * The wiring law: every screen the review named composes the tail. A peel
     * of any single site (or a never-wired one) reddens here — the helper alone
     * fixes nothing.
     */
    public function testEveryNamedWindowedListScreenComposesTheTail(): void
    {
        $wired = [
            'AdminUsersScreen.php',
            'AudiobookDetailScreen.php',
            'CastScreen.php',
            'MusicArtistsScreen.php',
            'MusicScreen.php',
            'AdminWatchHistoryScreen.php',
        ];

        foreach ($wired as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Screen/' . $file);

            self::assertStringContainsString(
                'Table::positionIndicator(',
                $source,
                "{$file} lost its M-CONSOLE-75 position tail"
            );
        }
    }

    // ---- M-CONSOLE-76: shimmer loaders ------------------------------------

    public function testCollectionsLoadingBodyShimmersAndIsDriven(): void
    {
        $screen = new CollectionsScreen(new ApiClient('https://srv', new FakeTransport()), 80, 24);

        self::assertInstanceOf(Loadable::class, $screen, 'the App only ticks a Loadable top screen');
        self::assertInstanceOf(Shimmering::class, $screen, 'the App only phases a Shimmering top screen');
        self::assertTrue($screen->isLoading());

        $view = $screen->view();

        self::assertStringContainsString('Loading collections…', $view);
        self::assertStringContainsString('░', $view, 'a skeleton, not a bare static line');
        self::assertNotSame(
            $view,
            $screen->withShimmerPhase(5)->view(),
            'the phase actually moves the band'
        );
    }

    public function testAdminUsersLoadingBodyShimmersAndStopsWhenLoaded(): void
    {
        $screen = new AdminUsersScreen(new AdminClient($this->authedApi()), cols: 120, rows: 40);

        self::assertInstanceOf(Loadable::class, $screen);
        self::assertInstanceOf(Shimmering::class, $screen);
        self::assertTrue($screen->isLoading());

        $view = $screen->view();

        self::assertStringContainsString('Loading users…', $view);
        self::assertStringContainsString('░', $view);

        $loaded = $this->loadedAdminUsers();

        self::assertFalse($loaded->isLoading(), 'the tick must stop once the table is up');
        self::assertStringNotContainsString('░', $loaded->view());
    }

    public function testMusicLoadingBodyShimmersAndStopsWhenLoaded(): void
    {
        $screen = $this->musicScreen();

        self::assertInstanceOf(Loadable::class, $screen);
        self::assertInstanceOf(Shimmering::class, $screen);
        self::assertTrue($screen->isLoading());

        $view = $screen->view();

        self::assertStringContainsString('Loading music…', $view);
        self::assertStringContainsString('░', $view);

        $loaded = $this->loadedMusic();

        self::assertFalse($loaded->isLoading());
    }

    // ---- builders ---------------------------------------------------------

    private function authedApi(): ApiClient
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return $api;
    }

    private function loadedAdminUsers(): AdminUsersScreen
    {
        $screen = new AdminUsersScreen(new AdminClient($this->authedApi()), cols: 120, rows: 40);
        [$screen] = $screen->update(new AdminUsersLoadedMsg([
            AdminUser::fromArray([
                'id' => 'u-1', 'username' => 'bob', 'email' => 'bob@x',
                'is_admin' => 1, 'status' => 'active', 'last_login' => '2026-06-26',
            ]),
            AdminUser::fromArray([
                'id' => 'u-2', 'username' => 'amy', 'email' => 'amy@x',
                'is_admin' => 0, 'status' => 'pending', 'last_login' => null,
            ]),
        ]));

        return $screen;
    }

    private function loadedMusic(): MusicScreen
    {
        $screen = $this->musicScreen();

        [$screen] = $screen->update(new MusicRangeLoadedMsg(new MusicRange([
            0 => Album::fromArray([
                'name' => 'Abbey Road', 'artist' => 'The Beatles',
                'year' => 1969, 'track_count' => 17, 'tracks' => [],
            ]),
            1 => Album::fromArray([
                'name' => 'Kind of Blue', 'artist' => null,
                'year' => null, 'track_count' => 5, 'tracks' => [],
            ]),
        ], 2), 0));

        return $screen;
    }

    private function adminClient(): AdminClient
    {
        return new AdminClient($this->authedApi());
    }

    private function musicScreen(): MusicScreen
    {
        return new MusicScreen(
            new MusicStore(new ApiClient('https://srv', new FakeTransport())),
            cols: 120,
            rows: 40,
        );
    }

    private function entry(string $id): WatchHistoryEntry
    {
        return new WatchHistoryEntry(
            id: $id,
            mediaItemId: $id,
            mediaName: 'Film ' . $id,
            mediaType: 'movie',
            libraryId: 'l1',
            userId: 'u1',
            username: 'alice',
            displayName: 'Alice',
            profileName: 'Home',
            lastWatchedAt: '2026-10-28T12:00:00+00:00',
            completedAt: '',
            progressPercent: 42.0,
            playbackStatus: 'watching',
        );
    }
}

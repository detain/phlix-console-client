<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\RecentlyWatchedItem;
use Phlix\Console\Msg\CollectionsLoadedMsg;
use Phlix\Console\Msg\FavoritesLoadedMsg;
use Phlix\Console\Msg\PlaylistsLoadedMsg;
use Phlix\Console\Msg\RecommendationsLoadedMsg;
use Phlix\Console\Msg\WatchHistoryLoadedMsg;
use Phlix\Console\Screen\CollectionsScreen;
use Phlix\Console\Screen\FavoritesScreen;
use Phlix\Console\Screen\PlaylistsScreen;
use Phlix\Console\Screen\RecommendationsScreen;
use Phlix\Console\Screen\WatchHistoryScreen;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

/**
 * CU-047 [review console__uxdesign Major]: the five ▶-cursor lists rendered
 * EVERY item and let the content leaf clip the tail — once the selection
 * passed the viewport it walked into clipped rows: Down did nothing visible
 * and the rest of the list was unreachable. These tests drive each screen
 * through a 40-item page on a 24-row terminal (16 body rows) and demand the
 * cursor line ALWAYS carries the selected row — the list must window itself.
 */
final class ListCursorViewportTest extends TestCase
{
    private const ITEM_COUNT = 40;

    public function testFavoritesCursorNeverWalksIntoClippedRows(): void
    {
        $screen = new FavoritesScreen(new \Phlix\Console\Store\FavoritesStore($this->api()), 80, 24);
        [$screen] = $screen->update(new FavoritesLoadedMsg($this->mediaItems()));

        for ($i = 0; $i < self::ITEM_COUNT; $i++) {
            $view = $screen->withSelectedIndex($i)->view();
            $this->assertCursorCarries($view, $this->itemName($i), 'Favorites');
        }
    }

    public function testWatchHistoryCursorNeverWalksIntoClippedRows(): void
    {
        $screen = new WatchHistoryScreen($this->api(), 80, 24);
        [$screen] = $screen->update(new WatchHistoryLoadedMsg($this->historyItems()));

        for ($i = 0; $i < self::ITEM_COUNT; $i++) {
            $view = $screen->withSelectedIndex($i)->view();
            $this->assertCursorCarries($view, $this->itemName($i), 'Watch history');
        }
    }

    public function testCollectionsCursorNeverWalksIntoClippedRows(): void
    {
        $screen = new CollectionsScreen($this->api(), 80, 24);
        [$screen] = $screen->update(new CollectionsLoadedMsg($this->mediaItems()));

        for ($i = 0; $i < self::ITEM_COUNT; $i++) {
            $view = $screen->withSelectedIndex($i)->view();
            $this->assertCursorCarries($view, $this->itemName($i), 'Collections');
        }
    }

    public function testPlaylistsCursorNeverWalksIntoClippedRows(): void
    {
        $screen = new PlaylistsScreen($this->api(), 80, 24);
        [$screen] = $screen->update(new PlaylistsLoadedMsg($this->mediaItems()));

        for ($i = 0; $i < self::ITEM_COUNT; $i++) {
            $view = $screen->withSelectedIndex($i)->view();
            $this->assertCursorCarries($view, $this->itemName($i), 'Playlists');
        }
    }

    public function testRecommendationsCursorNeverWalksIntoClippedRows(): void
    {
        $screen = new RecommendationsScreen($this->api(), 80, 24);
        [$screen] = $screen->update(new RecommendationsLoadedMsg($this->recommendationRows()));

        for ($i = 0; $i < self::ITEM_COUNT; $i++) {
            $view = $screen->withSelectedIndex($i)->view();
            $this->assertCursorCarries($view, $this->itemName($i), 'Recommendations');
        }
    }

    /**
     * The delete-prompt branch spends 4 of the 16 body rows (blank, blank+
     * prompt, cancel hint) — the window must shrink with it, or the cursor
     * hides behind the panel bottom exactly when the user is navigating to
     * confirm.
     */
    public function testCollectionsCursorStaysVisibleBesideTheDeletePrompt(): void
    {
        $screen = new CollectionsScreen($this->api(), 80, 24);
        [$screen] = $screen->update(new CollectionsLoadedMsg($this->mediaItems()));
        [$screen] = $screen->withSelectedIndex(30)->update(new KeyMsg(KeyType::Char, 'd'));

        $view = $screen->view();
        $this->assertStringContainsString('⚠', $view, 'the prompt branch renders');
        for ($i = 0; $i < self::ITEM_COUNT; $i++) {
            $this->assertCursorCarries($screen->withSelectedIndex($i)->view(), $this->itemName($i), 'Collections delete prompt');
        }
    }

    public function testPlaylistsCursorStaysVisibleBesideTheDeletePrompt(): void
    {
        $screen = new PlaylistsScreen($this->api(), 80, 24);
        [$screen] = $screen->update(new PlaylistsLoadedMsg($this->mediaItems()));
        [$screen] = $screen->withSelectedIndex(30)->update(new KeyMsg(KeyType::Char, 'd'));

        $view = $screen->view();
        $this->assertStringContainsString('⚠', $view, 'the prompt branch renders');
        for ($i = 0; $i < self::ITEM_COUNT; $i++) {
            $this->assertCursorCarries($screen->withSelectedIndex($i)->view(), $this->itemName($i), 'Playlists delete prompt');
        }
    }

    // ---- builders -------------------------------------------------------

    private function api(): \Phlix\Console\Api\ApiClient
    {
        return new \Phlix\Console\Api\ApiClient('https://srv', new \Phlix\Console\Tests\Api\FakeTransport());
    }

    /** Zero-padded two-digit names: no needle is a substring of another. */
    private function itemName(int $i): string
    {
        return sprintf('Item %02d', $i + 1);
    }

    /** @return list<MediaItem> */
    private function mediaItems(): array
    {
        $items = [];
        for ($i = 0; $i < self::ITEM_COUNT; $i++) {
            $items[] = MediaItem::fromArray(['id' => 'm' . $i, 'name' => $this->itemName($i), 'type' => 'movie']);
        }

        return $items;
    }

    /** @return list<RecentlyWatchedItem> */
    private function historyItems(): array
    {
        $items = [];
        for ($i = 0; $i < self::ITEM_COUNT; $i++) {
            $items[] = new RecentlyWatchedItem(
                id: 'w' . $i,
                mediaItemId: 'm' . $i,
                name: $this->itemName($i),
                type: 'movie',
                positionTicks: 0,
                durationTicks: 1,
                playbackStatus: 'watching',
                progressPercent: 0.0,
                updatedAt: '2026-01-01T00:00:00+00:00',
            );
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function recommendationRows(): array
    {
        $rows = [];
        for ($i = 0; $i < self::ITEM_COUNT; $i++) {
            $rows[] = ['id' => 'r' . $i, 'title' => $this->itemName($i), 'year' => 2026, 'score' => 0.5];
        }

        return $rows;
    }

    /**
     * The wiring law: every screen the review named windows through the
     * shared trait/helper — a peel of any single site (or a hand-rolled
     * window formula) reddens here. Table's own scrollY must delegate too,
     * or the two window families drift.
     */
    public function testEveryNamedListScreenWindowsThroughTheSharedHelper(): void
    {
        $root = dirname(__DIR__, 2) . '/src/';

        foreach (['FavoritesScreen', 'WatchHistoryScreen', 'CollectionsScreen', 'PlaylistsScreen', 'RecommendationsScreen'] as $screen) {
            $source = (string) file_get_contents($root . 'Screen/' . $screen . '.php');
            self::assertStringContainsString('use WindowedList;', $source, "{$screen} dropped the windowing trait");
            self::assertStringContainsString('$this->listWindow($this->items', $source, "{$screen} lost its window call");
        }

        $detail = (string) file_get_contents($root . 'Screen/DetailScreen.php');
        self::assertStringContainsString('ListWindow::offset(', $detail, 'the More-Like-This section lost its window');

        $table = (string) file_get_contents($root . 'Ui/Table.php');
        self::assertStringContainsString('ListWindow::offset($count, $selected, $viewport)', $table, 'Table re-forked the window math');
    }

    private function assertCursorCarries(string $view, string $needle, string $screenLabel): void
    {
        $visible = false;
        foreach (explode("\n", $view) as $line) {
            if (str_contains($line, '▶') && str_contains($line, $needle)) {
                $visible = true;
                break;
            }
        }

        self::assertTrue(
            $visible,
            "{$screenLabel}: no rendered line carries the ▶ cursor and '{$needle}' — the selection walked into clipped rows"
        );
    }
}

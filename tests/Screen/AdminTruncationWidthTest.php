<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\WatchHistoryEntry;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminDuplicatesLoadedMsg;
use Phlix\Console\Msg\AdminWatchHistoryLoadedMsg;
use Phlix\Console\Msg\AdminWebhooksLoadedMsg;
use Phlix\Console\Screen\AdminDuplicatesScreen;
use Phlix\Console\Screen\AdminWatchHistoryScreen;
use Phlix\Console\Screen\AdminWebhooksScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\Util\Width;

/**
 * M-CONSOLE-47: the three admin screens that carried byte-identical private
 * truncate() helpers must hand their tables cells that fit the DISPLAY
 * budget, not the codepoint count. Driven through the public
 * update(LoadedMsg) seam into each screen's own row builder — the exact
 * cell that used to overflow the Table::render allotment.
 *
 * Red-on-parent: with the deleted helpers back in place, every budget
 * assert below fails ~2× over (the review's measurement: a 10-cell request
 * came back 19 columns wide for CJK).
 */
final class AdminTruncationWidthTest extends TestCase
{
    /** 20 CJK chars — 40 display cells, 20 codepoints. */
    private const CJK_40 = '怪物映画の非常に長いタイトルがありますね本当に';

    /** Two whole family-emoji clusters plus mixed-script tail — 33 cells. */
    private const EMOJI_NAME = "👨‍👩‍👦 party 👨‍👩‍👧 finale 🎬 encore 怪物";

    private function adminClient(): AdminClient
    {
        $api = new ApiClient('https://srv', new FakeTransport());
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminClient($api);
    }

    /**
     * @return list<list<string>>
     */
    private function rowsOf(object $screen, string $method = 'tableRows'): array
    {
        $rows = (new ReflectionMethod($screen, $method))->invoke($screen);
        self::assertIsArray($rows);

        return $rows;
    }

    private function entry(string $id, string $mediaName): WatchHistoryEntry
    {
        return new WatchHistoryEntry(
            id: $id,
            mediaItemId: $id,
            mediaName: $mediaName,
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

    public function testWatchHistoryMediaCellRespectsItsColumnBudget(): void
    {
        $screen = new AdminWatchHistoryScreen($this->adminClient(), cols: 90, rows: 20);
        [$screen] = $screen->update(new AdminWatchHistoryLoadedMsg([$this->entry('h1', self::CJK_40)]));

        $mediaCell = $this->rowsOf($screen)[0][2];

        self::assertSame(
            rtrim(Width::truncate(self::CJK_40, 17)) . '…',
            $mediaCell,
            'the Media cell is the fitted prefix plus one ellipsis — 8 fullwidth chars, never the 17 codepoints the old helper took (34 cells for an 18-cell column)',
        );
        self::assertLessThanOrEqual(18, Width::string($mediaCell));
        self::assertTrue(str_ends_with($mediaCell, '…'));
    }

    public function testWatchHistoryMediaCellNeverSeversAnEmojiCluster(): void
    {
        $screen = new AdminWatchHistoryScreen($this->adminClient(), cols: 90, rows: 20);
        [$screen] = $screen->update(new AdminWatchHistoryLoadedMsg([$this->entry('h2', self::EMOJI_NAME)]));

        $mediaCell = $this->rowsOf($screen)[0][2];

        self::assertLessThanOrEqual(18, Width::string($mediaCell));
        self::assertSame(rtrim(Width::truncate(self::EMOJI_NAME, 17)) . '…', $mediaCell);
        // The last codepoint before the ellipsis may never be a bare ZWJ:
        // that is the seam a codepoint cut leaves mid-sequence.
        $prefix = mb_substr($mediaCell, 0, -1, 'UTF-8');
        self::assertNotSame("\u{200D}", mb_substr($prefix, -1, 1, 'UTF-8'), 'no lone ZWJ directly before the ellipsis');
    }

    public function testDuplicatesPrimaryCellRespectsItsColumnBudget(): void
    {
        $screen = new AdminDuplicatesScreen($this->adminClient(), libraryId: 'l1', cols: 60, rows: 20);
        $groups = [[
            'canonical_key' => 'k1',
            'type' => 'movie',
            'library_id' => 'l1',
            'primary' => ['id' => 'p1', 'name' => self::CJK_40],
            'duplicates' => [['id' => 'd1', 'name' => self::CJK_40]],
        ]];
        [$screen] = $screen->update(new AdminDuplicatesLoadedMsg('l1', $groups));

        $primaryCell = $this->rowsOf($screen)[0][0];

        self::assertLessThanOrEqual(28, Width::string($primaryCell), 'cols-32 budget honored (was ~55 cells under the codepoint helper)');
        self::assertTrue(str_ends_with($primaryCell, '…'));
    }

    public function testDuplicatesGroupBodyKeepsCjkWithinTheViewport(): void
    {
        $screen = new AdminDuplicatesScreen($this->adminClient(), libraryId: 'l1', cols: 40, rows: 20);
        $groups = [[
            'canonical_key' => 'k1',
            'type' => 'movie',
            'library_id' => 'l1',
            'primary' => ['id' => 'p1', 'name' => 'short'],
            'duplicates' => [['id' => 'd1', 'name' => self::CJK_40]],
        ]];
        [$screen] = $screen->update(new AdminDuplicatesLoadedMsg('l1', $groups));

        $body = (new ReflectionMethod($screen, 'groupBody'))->invoke($screen);

        self::assertStringNotContainsString(self::CJK_40, $body, 'the 40-cell name must not reach the body whole');
        self::assertTrue(str_contains($body, '…'));
        $widths = array_map(static fn(string $l): int => Width::string($l), explode("\n", $body));
        self::assertLessThanOrEqual(
            40,
            max($widths),
            'no group-body line (table frame included) bills more than the viewport: seen ' . implode(',', $widths),
        );
    }

    public function testWebhooksUrlCellRespectsItsColumnBudget(): void
    {
        $screen = new AdminWebhooksScreen($this->adminClient(), cols: 80, rows: 20);
        $url = 'https://example.jp/webhook/' . self::CJK_40;
        $webhooks = [[
            'id' => 'w1',
            'url' => $url,
            'events' => ['media.play', 'library.update'],
            'enabled' => true,
        ]];
        [$screen] = $screen->update(new AdminWebhooksLoadedMsg($webhooks));

        $urlCell = $this->rowsOf($screen)[0][0];

        self::assertLessThanOrEqual(40, Width::string($urlCell), 'cols-40 budget honored (URLs carry IDN paths)');
        self::assertSame(rtrim(Width::truncate($url, 39)) . '…', $urlCell);
        self::assertTrue(str_ends_with($urlCell, '…'));
    }
}

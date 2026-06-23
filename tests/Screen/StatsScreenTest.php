<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\StatsFailedMsg;
use Phlix\Console\Msg\StatsLoadedMsg;
use Phlix\Console\Screen\StatsScreen;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

final class StatsScreenTest extends TestCase
{
    private function screenWith(FakeTransport $transport): StatsScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new StatsScreen(new LibrariesStore($api), cols: 120, rows: 40);
    }

    /**
     * A `{ "libraries": [ … ] }` envelope. A library row only needs the fields the
     * stats panel reads (name/type/item_count); the rest default via fromArray.
     *
     * @param list<array{name: string, type: string, item_count: int}> $rows
     * @return array{libraries: list<array<string, mixed>>}
     */
    private function librariesResponse(array $rows): array
    {
        $libraries = [];
        foreach ($rows as $i => $row) {
            $libraries[] = [
                'id' => 'lib-' . $i,
                'name' => $row['name'],
                'type' => $row['type'],
                'item_count' => $row['item_count'],
            ];
        }

        return ['libraries' => $libraries];
    }

    /** A mixed library set: two movie libs (summed), plus tv / music / book. */
    private function mixedResponse(): array
    {
        return $this->librariesResponse([
            ['name' => 'Films', 'type' => 'movie', 'item_count' => 120],
            ['name' => 'More Films', 'type' => 'movie', 'item_count' => 30],
            ['name' => 'Shows', 'type' => 'tv', 'item_count' => 40],
            ['name' => 'Tunes', 'type' => 'music', 'item_count' => 500],
            ['name' => 'Reads', 'type' => 'book', 'item_count' => 12],
        ]);
    }

    /** Load libraries into the screen (init → StatsLoadedMsg → update). */
    private function loaded(?array $response = null): StatsScreen
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $response ?? $this->mixedResponse()));
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(StatsLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    public function testInitFetchesTheLibraries(): void
    {
        $transport = (new FakeTransport())->json(200, $this->mixedResponse());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(StatsLoadedMsg::class, $msg);
        self::assertCount(5, $msg->libraries);
        self::assertStringContainsString('/api/v1/libraries', $transport->requestAt(0)['url']);
    }

    public function testAggregationGroupsByTypeWithCountsItemSumsAndTotals(): void
    {
        $loaded = $this->loaded();

        $stats = $loaded->stats();
        self::assertNotNull($stats);

        // Index by label for order-independent assertions on the values.
        $byLabel = [];
        foreach ($stats as $row) {
            $byLabel[$row['label']] = $row;
        }

        // The two movie libraries are summed into one row (2 libs, 150 items).
        self::assertSame(['label' => 'Movies', 'libraries' => 2, 'items' => 150], $byLabel['Movies']);
        self::assertSame(['label' => 'TV', 'libraries' => 1, 'items' => 40], $byLabel['TV']);
        self::assertSame(['label' => 'Music', 'libraries' => 1, 'items' => 500], $byLabel['Music']);
        self::assertSame(['label' => 'Books', 'libraries' => 1, 'items' => 12], $byLabel['Books']);
        self::assertCount(4, $stats, 'four distinct types');

        // Totals across every library.
        self::assertSame(5, $loaded->totalLibraries());
        self::assertSame(702, $loaded->totalItems());
    }

    public function testRowsUseFriendlyLabelsInTheFixedOrder(): void
    {
        // One library of each known type, supplied OUT of the canonical order.
        $loaded = $this->loaded($this->librariesResponse([
            ['name' => 'P', 'type' => 'photo', 'item_count' => 1],
            ['name' => 'Ab', 'type' => 'audiobook', 'item_count' => 1],
            ['name' => 'Bk', 'type' => 'book', 'item_count' => 1],
            ['name' => 'Mu', 'type' => 'music', 'item_count' => 1],
            ['name' => 'Se', 'type' => 'series', 'item_count' => 1],
            ['name' => 'Tv', 'type' => 'tv', 'item_count' => 1],
            ['name' => 'Mo', 'type' => 'movie', 'item_count' => 1],
        ]));

        $labels = array_map(static fn (array $r): string => $r['label'], $loaded->stats() ?? []);

        self::assertSame(['Movies', 'TV', 'Series', 'Music', 'Books', 'Audiobooks', 'Photos'], $labels);
    }

    public function testAnUnknownTypeFallsBackToUcfirstAndSortsAfterKnownTypes(): void
    {
        $loaded = $this->loaded($this->librariesResponse([
            ['name' => 'Comics Vol', 'type' => 'comic', 'item_count' => 7],
            ['name' => 'Films', 'type' => 'movie', 'item_count' => 3],
        ]));

        $labels = array_map(static fn (array $r): string => $r['label'], $loaded->stats() ?? []);

        // The known 'movie' type comes first; the unknown 'comic' → 'Comic' after it.
        self::assertSame(['Movies', 'Comic'], $labels);
    }

    public function testLoadedRendersTheTypeRowsAndTheTotalsLine(): void
    {
        $loaded = $this->loaded();

        self::assertTrue($loaded->isLoaded());

        $view = $loaded->view();
        // Column headers.
        self::assertStringContainsString('Type', $view);
        self::assertStringContainsString('Libraries', $view);
        self::assertStringContainsString('Items', $view);
        // Per-type rows (labels + numbers).
        self::assertStringContainsString('Movies', $view);
        self::assertStringContainsString('TV', $view);
        self::assertStringContainsString('Music', $view);
        self::assertStringContainsString('150', $view, 'the summed movie item count renders');
        self::assertStringContainsString('500', $view);
        // The totals line.
        self::assertStringContainsString('Total:', $view);
        self::assertStringContainsString('5 libraries', $view);
        self::assertStringContainsString('702 items', $view);
    }

    public function testTotalsLineSingularGrammarForOneLibraryAndItem(): void
    {
        $loaded = $this->loaded($this->librariesResponse([
            ['name' => 'Solo', 'type' => 'movie', 'item_count' => 1],
        ]));

        $view = $loaded->view();
        self::assertStringContainsString('1 library', $view);
        self::assertStringContainsString('1 item', $view);
        self::assertStringNotContainsString('1 libraries', $view);
        self::assertStringNotContainsString('1 items', $view);
    }

    public function testLoadingViewBeforeLibrariesArrive(): void
    {
        $screen = $this->screenWith((new FakeTransport())->pending());

        $view = $screen->view();
        self::assertFalse($screen->isLoaded());
        self::assertNull($screen->stats());
        self::assertStringContainsString('Loading stats', $view);
        self::assertStringContainsString('Stats', $view, 'the title fills the header during load');
    }

    public function testEmptyLibrarySetShowsTheEmptyState(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['libraries' => []]));
        [$loaded] = $screen->update($this->runCmd($screen->init()));

        self::assertTrue($loaded->isLoaded());
        self::assertSame([], $loaded->stats());
        self::assertSame(0, $loaded->totalLibraries());
        self::assertSame(0, $loaded->totalItems());
        self::assertStringContainsString('No libraries.', $loaded->view());
    }

    public function testFetchFailureShowsAnError(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('boom'));
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(StatsFailedMsg::class, $msg);
        self::assertSame('Could not load library stats.', $msg->reason);

        [$failed] = $screen->update($msg);
        self::assertStringContainsString('Could not load library stats.', $failed->view());
        self::assertSame('Could not load library stats.', $failed->error());
        self::assertNull($failed->stats(), 'a failed load leaves the stats unset');
    }

    public function testAuthErrorBecomesSessionExpired(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'unauthorized']);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testEscapeAndQNavigateBack(): void
    {
        $loaded = $this->loaded();

        [$sameEsc, $escCmd] = $loaded->update(new KeyMsg(KeyType::Escape));
        [$sameQ, $qCmd] = $loaded->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertInstanceOf(NavigateBackMsg::class, $escCmd?->__invoke());
        self::assertInstanceOf(NavigateBackMsg::class, $qCmd?->__invoke());
        self::assertSame($loaded, $sameEsc, 'going back does not mutate the screen');
        self::assertSame($loaded, $sameQ);
    }

    public function testUnhandledKeyAndMessageAreNoOps(): void
    {
        $loaded = $this->loaded();

        [$afterKey, $keyCmd] = $loaded->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($loaded, $afterKey);
        self::assertNull($keyCmd);

        [$afterArrow, $arrowCmd] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame($loaded, $afterArrow, 'the stats panel has no cursor — arrows are ignored');
        self::assertNull($arrowCmd);

        [$afterMsg, $msgCmd] = $loaded->update(new SessionExpiredMsg('ignored here'));
        self::assertSame($loaded, $afterMsg);
        self::assertNull($msgCmd);
    }

    public function testResizeStillRenders(): void
    {
        // A small window must still re-flow and render without error.
        [$small] = $this->loaded()->update(new WindowSizeMsg(60, 20));
        self::assertIsString($small->view());

        // At a comfortable size the type rows + totals are visible after the re-flow.
        [$big] = $this->loaded()->update(new WindowSizeMsg(100, 40));
        self::assertStringContainsString('Movies', $big->view());
        self::assertStringContainsString('Total:', $big->view());
    }

    public function testBreadcrumbLabelAndWithCrumbs(): void
    {
        $loaded = $this->loaded();
        self::assertSame('Stats', $loaded->crumbLabel());

        $withCrumbs = $loaded->withCrumbs(['Home', 'Stats']);
        self::assertStringContainsString('Home', $withCrumbs->view());
        self::assertStringContainsString('›', $withCrumbs->view());
    }

    public function testRendersUnderAColourTheme(): void
    {
        // Themed: a colour theme tints the brand; the stats content is unaffected.
        $themed = $this->loaded()->withTheme(Theme::midnight());

        $view = $themed->view();
        self::assertMatchesRegularExpression('/\e\[[0-9;]*m Phlix \e\[0m/', $view, 'the brand token is colour-wrapped');
        $stripped = preg_replace('/\e\[[0-9;]*m/', '', $view) ?? $view;
        self::assertStringContainsString('Movies', $stripped);
        self::assertStringContainsString('Total:', $stripped);
    }

    public function testDefaultRenderIsNocturneIdentity(): void
    {
        // The un-themed default render equals an explicit-Nocturne render, byte for
        // byte — the theme system is a no-op under Nocturne (the loaded view carries
        // the sugar-table header's own SGR, but it is identical in both renders).
        $loaded = $this->loaded();

        self::assertSame($loaded->view(), $loaded->withTheme(Theme::nocturne())->view());
    }

    public function testTheLoadingViewUnderNocturneCarriesNoBrandSgr(): void
    {
        // Before the table exists, the Nocturne frame is byte-clean (zero SGR) — the
        // brand/status tokens add nothing, exactly like the pre-theme chrome.
        $screen = $this->screenWith((new FakeTransport())->pending());

        self::assertStringNotContainsString("\e[", $screen->view(), 'the loading frame has zero SGR under Nocturne');
        self::assertSame($screen->view(), $screen->withTheme(Theme::nocturne())->view());
    }

    // ---- harness (mirrors MusicScreenTest) ----------------------------

    private function runCmd(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }

        $result = $cmd();
        if ($result instanceof BatchMsg) {
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                if ($msg !== null) {
                    return $msg;
                }
            }

            return null;
        }
        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? $msg : null;
        }

        return $result instanceof Msg ? $result : null;
    }

    private function await(PromiseInterface $promise, float $timeout = 5.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $timer = null;
        $settle = static function () use (&$timer): void {
            if ($timer !== null) {
                Loop::cancelTimer($timer);
                $timer = null;
            }
            Loop::stop();
        };
        $promise->then(
            function ($v) use (&$state, $settle): void {
                $state['value'] = $v;
                $state['done'] = true;
                $settle();
            },
            function ($e) use (&$state, $settle): void {
                $state['error'] = $e;
                $state['done'] = true;
                $settle();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
        }

        if (!$state['done']) {
            throw new \RuntimeException('cmd did not settle in time');
        }
        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\ChildPosterLoadedMsg;
use Phlix\Console\Msg\ChildrenFailedMsg;
use Phlix\Console\Msg\ChildrenLoadedMsg;
use Phlix\Console\Msg\DetailFailedMsg;
use Phlix\Console\Msg\DetailLoadedMsg;
use Phlix\Console\Msg\DetailPosterLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\CastRequestedMsg;
use Phlix\Console\Msg\OpenDetailMsg;
use Phlix\Console\Msg\PlayRequestedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Screen\DetailScreen;
use Phlix\Console\Store\MediaRange;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Mosaic\Mosaic;

final class DetailScreenTest extends TestCase
{
    private ?SocketServer $socket = null;

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    private function screenWith(FakeTransport $transport, ?PosterLoader $posters = null): DetailScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new DetailScreen(
            'm1',
            'The Matrix',
            new MediaStore($api),
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            cols: 120,
            rows: 40,
        );
    }

    /** The `{ "item": { … } }` detail envelope, with sensible movie defaults. */
    private function detailResponse(array $overrides = []): array
    {
        return ['item' => array_merge([
            'id' => 'm1',
            'name' => 'The Matrix',
            'type' => 'movie',
            'year' => 1999,
            'rating' => 'R',
            'runtime' => 136,
            'genres' => ['Action', 'Sci-Fi'],
            'director' => 'The Wachowskis',
            'actors' => ['Keanu Reeves', 'Laurence Fishburne'],
            'overview' => 'A hacker discovers the shocking truth about his reality.',
            'poster_url' => 'https://p/m1.jpg',
            'stream_url' => 'https://srv/media/m1/stream?sig=x',
        ], $overrides)];
    }

    /** Load an item into the screen (init → DetailLoadedMsg → update). */
    private function loaded(array $overrides = []): DetailScreen
    {
        $transport = (new FakeTransport())->json(200, $this->detailResponse($overrides));
        $screen = $this->screenWith($transport);
        $msg = $this->runBatch($screen->init())[0];
        self::assertInstanceOf(DetailLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    public function testInitFetchesTheCorrectItemEndpoint(): void
    {
        $transport = (new FakeTransport())->json(200, $this->detailResponse());
        $screen = $this->screenWith($transport);

        $msg = $this->runBatch($screen->init())[0];

        self::assertInstanceOf(DetailLoadedMsg::class, $msg);
        self::assertSame('m1', $msg->item->id);
        self::assertStringContainsString('/api/v1/media/m1', $transport->requestAt(0)['url']);
    }

    public function testLoadedRendersTitleMetadataAndSynopsis(): void
    {
        $loaded = $this->loaded();

        self::assertTrue($loaded->isLoaded());
        self::assertSame('m1', $loaded->item()?->id);

        $view = $loaded->view();
        self::assertStringContainsString('The Matrix', $view);
        self::assertStringContainsString('Sci-Fi', $view);
        self::assertStringContainsString('1999', $view);
        self::assertStringContainsString('2h 16m', $view, '136 minutes → 2h 16m');
        self::assertStringContainsString('Directed by', $view);
        self::assertStringContainsString('Keanu', $view);
        self::assertStringContainsString('hacker', $view, 'the synopsis is rendered');
    }

    public function testRuntimeFallsBackToDurationSeconds(): void
    {
        // No TMDB runtime, but a probed duration of 5400s → 90m → 1h 30m.
        $view = $this->loaded(['runtime' => null, 'duration' => 5400])->view();

        self::assertStringContainsString('1h 30m', $view);
    }

    public function testEpisodeMetaLineShowsSeasonEpisodeAndTitle(): void
    {
        $view = $this->loaded([
            'name' => 'My Show',
            'type' => 'episode',
            'season_number' => 1,
            'episode_number' => 2,
            'episode_title' => 'The Return',
            'runtime' => 24,
        ])->view();

        self::assertStringContainsString('S01E02', $view);
        self::assertStringContainsString('The Return', $view);
    }

    public function testLoadingViewBeforeTheItemArrives(): void
    {
        $screen = $this->screenWith((new FakeTransport())->pending());

        $view = $screen->view();
        self::assertStringContainsString('Loading', $view);
        self::assertStringContainsString('The Matrix', $view, 'the seed name fills the header during load');
    }

    public function testEscapeNavigatesBack(): void
    {
        $screen = $this->screenWith((new FakeTransport())->pending());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    public function testPlayKeyOnAPlayableItemRequestsPlayback(): void
    {
        // The default detailResponse carries a signed stream_url.
        $loaded = $this->loaded();

        [$same, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'p'));

        $msg = $cmd?->__invoke();
        self::assertInstanceOf(PlayRequestedMsg::class, $msg);
        self::assertSame('m1', $msg->item->id);
        self::assertSame('https://srv/media/m1/stream?sig=x', $msg->item->streamUrl);
        self::assertFalse($same->showsPlayNotice(), 'a playable item plays — no notice');
    }

    public function testPlayKeyWithoutAStreamShowsNoSourceNotice(): void
    {
        $loaded = $this->loaded(['stream_url' => null]);

        [$next, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'p'));

        self::assertNull($cmd, 'nothing to play');
        self::assertTrue($next->showsPlayNotice());
        self::assertStringContainsString('no playable source', $next->view());
    }

    public function testCastKeyOnAPlayableItemRequestsCast(): void
    {
        // The default detailResponse carries a signed stream_url.
        $loaded = $this->loaded();

        [$same, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'C'));

        $msg = $cmd?->__invoke();
        self::assertInstanceOf(CastRequestedMsg::class, $msg);
        self::assertSame('m1', $msg->item->id);
        self::assertSame('https://srv/media/m1/stream?sig=x', $msg->item->streamUrl);
        self::assertFalse($same->showsPlayNotice(), 'casting shows no play notice');
    }

    public function testCastKeyWithoutAStreamIsANoOp(): void
    {
        $loaded = $this->loaded(['stream_url' => null]);

        [$next, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'C'));

        self::assertNull($cmd, 'nothing to cast');
        self::assertSame($loaded, $next, 'no state change without a stream');
    }

    public function testHintMentionsCast(): void
    {
        self::assertStringContainsString('cast', $this->loaded()->view());
    }

    public function testUpAndDownScrollTheSynopsisAndClampAtTheTop(): void
    {
        $loaded = $this->loaded(['overview' => str_repeat('word ', 400)]);

        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertNotSame($loaded, $down, 'down scrolls the synopsis');

        [$up] = $loaded->update(new KeyMsg(KeyType::Up));
        self::assertSame($loaded, $up, 'up at the top is a no-op (clamped at 0)');
    }

    public function testFetchFailureShowsAnError(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('boom'));
        $screen = $this->screenWith($transport);

        $msg = $this->runBatch($screen->init())[0];
        self::assertInstanceOf(DetailFailedMsg::class, $msg);

        [$failed] = $screen->update($msg);
        self::assertStringContainsString('Could not load', $failed->view());
        self::assertStringContainsString('Could not load', (string) $failed->error());
    }

    public function testUnhandledKeyAndMessageAreNoOps(): void
    {
        $loaded = $this->loaded();

        // A key the detail screen doesn't bind (e.g. ←) changes nothing.
        [$afterKey, $keyCmd] = $loaded->update(new KeyMsg(KeyType::Left));
        self::assertSame($loaded, $afterKey);
        self::assertNull($keyCmd);

        // A message it doesn't handle is ignored.
        [$afterMsg, $msgCmd] = $loaded->update(new SessionExpiredMsg('ignored here'));
        self::assertSame($loaded, $afterMsg);
        self::assertNull($msgCmd);
    }

    public function testLengthOmittedWhenNoRuntimeOrDuration(): void
    {
        // Neither TMDB minutes nor a probed duration → no length token, no crash.
        $view = $this->loaded(['runtime' => null, 'duration' => null])->view();

        self::assertStringContainsString('Movie', $view);
        self::assertStringContainsString('1999', $view);
    }

    public function testAuthErrorBecomesSessionExpired(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'unauthorized']);
        $screen = $this->screenWith($transport);

        $msg = $this->runBatch($screen->init())[0];

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testNoPosterSkipsTheHeroFetchAndStillRenders(): void
    {
        $transport = (new FakeTransport())->json(200, $this->detailResponse(['poster_url' => null]));
        $screen = $this->screenWith($transport);
        $msg = $this->runBatch($screen->init())[0];

        [$loaded, $cmd] = $screen->update($msg);

        self::assertFalse($loaded->hasHero());
        self::assertNull($cmd, 'no poster → no hero fetch Cmd');
        self::assertIsString($loaded->view(), 'the placeholder still renders');
    }

    public function testMissingSynopsisFallsBack(): void
    {
        $view = $this->loaded(['overview' => null])->view();

        self::assertStringContainsString('No synopsis available', $view);
    }

    public function testHeroPosterFetchPopulatesTheHero(): void
    {
        $port = $this->startPosterServer();
        $transport = (new FakeTransport())->json(200, $this->detailResponse(['poster_url' => "http://127.0.0.1:{$port}/p.png"]));
        $screen = $this->screenWith($transport);

        $loadMsg = $this->runBatch($screen->init())[0];
        [$loaded, $heroCmd] = $screen->update($loadMsg);
        self::assertInstanceOf(\Closure::class, $heroCmd, 'a poster URL kicks off a hero fetch');

        $posterMsg = $this->runCmd($heroCmd);
        self::assertInstanceOf(DetailPosterLoadedMsg::class, $posterMsg);

        [$withHero] = $loaded->update($posterMsg);
        self::assertTrue($withHero->hasHero());
    }

    public function testResizeStillRenders(): void
    {
        [$next] = $this->loaded()->update(new WindowSizeMsg(60, 20));

        self::assertIsString($next->view());
    }

    public function testLeafEnterIsANoOp(): void
    {
        // A movie has no children — Enter does nothing (only containers drill).
        $loaded = $this->loaded();
        [$same, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));

        self::assertSame($loaded, $same);
        self::assertFalse($same->isContainer());
        self::assertNull($cmd);
    }

    // ---- container mode: series → season → episode ---------------------

    /**
     * Load a container item (series/season) and its first window of children.
     *
     * @param list<array<string,mixed>> $childRows
     */
    private function loadedContainer(string $type, array $childRows): DetailScreen
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse(['type' => $type, 'name' => 'My Show']))
            ->json(200, ['items' => $childRows, 'total' => count($childRows), 'limit' => 50, 'offset' => 0]);
        $screen = $this->screenWith($transport);

        $itemMsg = $this->runBatch($screen->init())[0];
        self::assertInstanceOf(DetailLoadedMsg::class, $itemMsg);
        [$screen, $childCmd] = $screen->update($itemMsg);
        self::assertTrue($screen->isContainer(), 'a series/season loads as a container');
        self::assertInstanceOf(\Closure::class, $childCmd, 'a container fetches its children on load');

        $rangeMsg = $this->runBatch($childCmd)[0];
        self::assertInstanceOf(ChildrenLoadedMsg::class, $rangeMsg);

        return $screen->update($rangeMsg)[0];
    }

    public function testSeriesRendersAsASeasonGrid(): void
    {
        $screen = $this->loadedContainer('series', [
            ['id' => 's1', 'name' => 'Season 1', 'type' => 'season'],
            ['id' => 's2', 'name' => 'Season 2', 'type' => 'season'],
        ]);

        self::assertTrue($screen->isContainer());
        self::assertSame(2, $screen->childGrid()?->total());

        $view = $screen->view();
        self::assertStringContainsString('My Show', $view);
        self::assertStringContainsString('2 seasons', $view);
        self::assertStringContainsString('Season 1', $view, 'the season cards render');
    }

    public function testSeasonContainerLabelsEpisodesAndIsSingularForOne(): void
    {
        $view = $this->loadedContainer('season', [
            ['id' => 'e1', 'name' => 'Pilot', 'type' => 'episode'],
        ])->view();

        self::assertStringContainsString('1 episode', $view);
        self::assertStringNotContainsString('1 episodes', $view, 'singular when there is one child');
    }

    public function testEnterOnAChildOpensItsDetail(): void
    {
        $screen = $this->loadedContainer('series', [
            ['id' => 's1', 'name' => 'Season 1', 'type' => 'season'],
        ]);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenDetailMsg::class, $msg, 'a season drills into its episodes');
        self::assertSame('s1', $msg->id);
        self::assertSame('Season 1', $msg->name);
    }

    public function testArrowMovesTheChildCursor(): void
    {
        $screen = $this->loadedContainer('series', [
            ['id' => 's1', 'name' => 'Season 1', 'type' => 'season'],
            ['id' => 's2', 'name' => 'Season 2', 'type' => 'season'],
            ['id' => 's3', 'name' => 'Season 3', 'type' => 'season'],
        ]);
        self::assertSame(0, $screen->childGrid()?->cursorIndex());

        [$moved] = $screen->update(new KeyMsg(KeyType::Right));

        self::assertSame(1, $moved->childGrid()?->cursorIndex());
    }

    public function testContainerGridNavigationKeys(): void
    {
        // Enough children to span several rows so the movement keys actually move.
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = ['id' => "e{$i}", 'name' => "Ep {$i}", 'type' => 'episode'];
        }
        $screen = $this->loadedContainer('season', $rows);

        foreach ([KeyType::Down, KeyType::Right, KeyType::End, KeyType::PageUp, KeyType::Home, KeyType::PageDown, KeyType::Up, KeyType::Left] as $key) {
            [$screen] = $screen->update(new KeyMsg($key));
            self::assertInstanceOf(DetailScreen::class, $screen);
        }

        // A key the grid doesn't consume is a no-op.
        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testChildPosterForAnUnknownCellIsDropped(): void
    {
        $screen = $this->loadedContainer('series', [
            ['id' => 's1', 'name' => 'Season 1', 'type' => 'season'],
        ]);

        [$after] = $screen->update(new ChildPosterLoadedMsg('m1', 999, 'POSTER'));

        self::assertSame($screen, $after, 'a poster for a non-existent cell is dropped');
    }

    public function testEnterOnAnEmptyContainerIsANoOp(): void
    {
        $screen = $this->loadedContainer('series', []);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd);
        self::assertStringContainsString('0 seasons', $screen->view());
    }

    public function testChildrenForADifferentParentAreIgnored(): void
    {
        // A late result tagged for another stacked DetailScreen must not touch
        // this grid (series → season → episode all reuse this screen).
        $screen = $this->loadedContainer('series', [
            ['id' => 's1', 'name' => 'Season 1', 'type' => 'season'],
        ]);

        [$after] = $screen->update(new ChildrenLoadedMsg('SOME-OTHER-ID', new MediaRange([], 99)));

        self::assertSame($screen, $after, 'a mismatched parentId is ignored');
        self::assertSame(1, $after->childGrid()?->total(), 'the grid is unchanged');
    }

    public function testChildPosterForADifferentParentIsIgnored(): void
    {
        $screen = $this->loadedContainer('series', [
            ['id' => 's1', 'name' => 'Season 1', 'type' => 'season', 'poster_url' => 'https://p/s1.jpg'],
        ]);

        [$after] = $screen->update(new ChildPosterLoadedMsg('SOME-OTHER-ID', 0, 'ANSI'));

        self::assertSame($screen, $after, 'a mismatched poster is ignored');
        self::assertFalse($after->childGrid()?->item(0)?->hasPoster() ?? true, 'the cell keeps its skeleton');
    }

    public function testChildPosterPopulatesTheCorrectCell(): void
    {
        // The series id is 'm1' (the detailResponse default), so a poster tagged
        // 'm1' belongs to this screen.
        $screen = $this->loadedContainer('series', [
            ['id' => 's1', 'name' => 'Season 1', 'type' => 'season', 'poster_url' => 'https://p/s1.jpg'],
        ]);

        [$withPoster] = $screen->update(new ChildPosterLoadedMsg('m1', 0, 'POSTER-ANSI'));

        self::assertTrue($withPoster->childGrid()?->item(0)?->hasPoster() ?? false);
    }

    public function testContainerChildrenFailureShowsError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse(['type' => 'series']))
            ->fail(new \RuntimeException('boom'));
        $screen = $this->screenWith($transport);

        $itemMsg = $this->runBatch($screen->init())[0];
        [$screen, $childCmd] = $screen->update($itemMsg);
        $failMsg = $this->runBatch($childCmd)[0];
        self::assertInstanceOf(ChildrenFailedMsg::class, $failMsg);

        [$failed] = $screen->update($failMsg);
        self::assertStringContainsString('Could not load', $failed->view());
    }

    public function testChildrenAuthErrorBecomesSessionExpired(): void
    {
        // The item loads, but the children fetch 401s (session expired mid-drill).
        $transport = (new FakeTransport())
            ->json(200, $this->detailResponse(['type' => 'series']))
            ->json(401, ['error' => 'unauthorized']);
        $screen = $this->screenWith($transport);

        $itemMsg = $this->runBatch($screen->init())[0];
        [, $childCmd] = $screen->update($itemMsg);
        $childMsg = $this->runBatch($childCmd)[0];

        self::assertInstanceOf(SessionExpiredMsg::class, $childMsg);
    }

    public function testScrollingRevealsAndLoadsMoreChildPosters(): void
    {
        // A container with many poster-bearing children; jumping to the end
        // reveals cells beyond the first window that must be fetched + rendered.
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = ['id' => "e{$i}", 'name' => "Ep {$i}", 'type' => 'episode', 'poster_url' => "https://p/e{$i}.jpg"];
        }
        $screen = $this->loadedContainer('season', $rows);

        [$moved, $cmd] = $screen->update(new KeyMsg(KeyType::End));

        self::assertSame(39, $moved->childGrid()?->cursorIndex());
        self::assertInstanceOf(\Closure::class, $cmd, 'the newly revealed window fetches range + posters');
    }

    public function testContainerResizeStillRenders(): void
    {
        $screen = $this->loadedContainer('series', [
            ['id' => 's1', 'name' => 'Season 1', 'type' => 'season'],
        ]);

        [$resized] = $screen->update(new WindowSizeMsg(60, 20));

        self::assertIsString($resized->view());
    }

    // ---- harness (mirrors LibraryScreenTest) ---------------------------

    /** @return list<Msg> */
    private function runBatch(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }

        $result = $cmd();

        if ($result instanceof BatchMsg) {
            $msgs = [];
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                if ($msg !== null) {
                    $msgs[] = $msg;
                }
            }

            return $msgs;
        }

        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? [$msg] : [];
        }

        return $result instanceof Msg ? [$result] : [];
    }

    private function runCmd(\Closure $cmd): ?Msg
    {
        $result = $cmd();
        if ($result instanceof AsyncCmd) {
            return $this->await($result->promise);
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

    private function startPosterServer(): int
    {
        $img = imagecreatetruecolor(8, 12);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 70, 120, 180));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        $server = new HttpServer(static fn (ServerRequestInterface $r): Response => new Response(200, ['Content-Type' => 'image/png'], $png));
        $this->socket = new SocketServer('127.0.0.1:0');
        $server->listen($this->socket);

        return (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }
}

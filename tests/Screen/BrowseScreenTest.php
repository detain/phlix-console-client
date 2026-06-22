<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\ContinueWatchingLoadedMsg;
use Phlix\Console\Msg\LibrariesFailedMsg;
use Phlix\Console\Msg\LibrariesLoadedMsg;
use Phlix\Console\Msg\LibraryMediaLoadedMsg;
use Phlix\Console\Msg\OpenLibraryMsg;
use Phlix\Console\Msg\PosterLoadedMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Store\LibrariesStore;
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
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Mosaic\Mosaic;

final class BrowseScreenTest extends TestCase
{
    private ?SocketServer $socket = null;

    protected function tearDown(): void
    {
        $this->socket?->close();
        $this->socket = null;
        parent::tearDown();
    }

    private function screen(): BrowseScreen
    {
        return $this->screenWith(new FakeTransport());
    }

    private function screenWith(FakeTransport $transport, ?PosterLoader $posters = null): BrowseScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new BrowseScreen(
            AuthUser::fromArray(['id' => 'u', 'username' => 'joe', 'display_name' => 'Joe Huss']),
            new LibrariesStore($api),
            new MediaStore($api),
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            cols: 120,
            rows: 40,
        );
    }

    private function library(string $id, string $name): Library
    {
        return Library::fromArray(['id' => $id, 'name' => $name, 'type' => 'movie']);
    }

    private function page(string ...$ids): MediaPage
    {
        $items = [];
        foreach ($ids as $id) {
            $items[] = ['id' => $id, 'name' => 'Item ' . $id, 'type' => 'movie', 'poster_url' => "https://p/{$id}.jpg"];
        }

        return MediaPage::fromArray(['items' => $items, 'total' => count($ids), 'limit' => 18, 'offset' => 0]);
    }

    public function testInitLoadsData(): void
    {
        self::assertInstanceOf(\Closure::class, $this->screen()->init());
    }

    public function testLoadingViewBeforeData(): void
    {
        $view = $this->screen()->view();

        self::assertStringContainsString('Loading', $view);
        self::assertStringContainsString('Joe Huss', $view);
    }

    public function testLibrariesLoadedCreatesEmptyRailsAndFetchesMedia(): void
    {
        [$next, $cmd] = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('lib-a', 'Movies'),
            $this->library('lib-b', 'TV'),
        ]));

        self::assertInstanceOf(BrowseScreen::class, $next);
        self::assertSame(['lib-a', 'lib-b'], $next->railIds());
        self::assertTrue($next->rail('lib-a')?->isEmpty());
        self::assertInstanceOf(\Closure::class, $cmd, 'fetches each library\'s media');
    }

    public function testLibraryMediaPopulatesRailAndLoadsPosters(): void
    {
        [$withRails] = $this->screen()->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies')]));

        [$next, $cmd] = $withRails->update(new LibraryMediaLoadedMsg('lib-a', $this->page('m1', 'm2')));

        $rail = $next->rail('lib-a');
        self::assertNotNull($rail);
        self::assertCount(2, $rail->cards);
        self::assertSame('m1', $rail->cards[0]->id);
        self::assertInstanceOf(\Closure::class, $cmd, 'loads posters for the cards');
    }

    public function testMediaForUnknownLibraryIsIgnored(): void
    {
        [$next, $cmd] = $this->screen()->update(new LibraryMediaLoadedMsg('ghost', $this->page('m1')));

        self::assertSame([], $next->railIds());
        self::assertNull($cmd);
    }

    public function testContinueWatchingPrependsRail(): void
    {
        $entry = ContinueWatchingItem::fromArray([
            'media_item_id' => 'cw1', 'name' => 'Show', 'position_ticks' => 3, 'duration_ticks' => 10,
            'metadata' => ['poster_url' => 'https://p/cw.jpg'],
        ]);

        [$next] = $this->screen()
            ->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies')]))[0]
            ->update(new ContinueWatchingLoadedMsg([$entry]));

        self::assertSame(['continue', 'lib-a'], $next->railIds(), 'continue watching comes first');
        self::assertCount(1, $next->rail('continue')?->cards ?? []);
    }

    public function testEmptyContinueWatchingAddsNoRail(): void
    {
        [$next] = $this->screen()->update(new ContinueWatchingLoadedMsg([]));

        self::assertSame([], $next->railIds());
    }

    public function testContinueWatchingPrependKeepsTheFocusedRail(): void
    {
        // Libraries load first; the user moves focus to the second library.
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('lib-a', 'A'),
            $this->library('lib-b', 'B'),
        ]))[0];
        [$moved] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame('lib-b', $moved->railIds()[$moved->railCursor()]);

        // Then Continue Watching resolves and prepends its rail.
        $entry = ContinueWatchingItem::fromArray(['media_item_id' => 'cw', 'name' => 'X', 'position_ticks' => 1, 'duration_ticks' => 2]);
        [$next] = $moved->update(new ContinueWatchingLoadedMsg([$entry]));

        self::assertSame(['continue', 'lib-a', 'lib-b'], $next->railIds());
        self::assertSame('lib-b', $next->railIds()[$next->railCursor()], 'focus stays on the same rail, not shifted');
    }

    public function testReloadingFewerLibrariesClampsTheCursor(): void
    {
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('a', 'A'),
            $this->library('b', 'B'),
            $this->library('c', 'C'),
        ]))[0];
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(2, $screen->railCursor());

        [$reloaded] = $screen->update(new LibrariesLoadedMsg([$this->library('a', 'A')]));

        self::assertSame(0, $reloaded->railCursor(), 'cursor clamped into the smaller rail set');
    }

    public function testPosterLoadedFillsTheCard(): void
    {
        $screen = $this->withLibraryMedia('lib-a', 'Movies', 'm1', 'm2');
        $cardId = $screen->rail('lib-a')?->cards[0]->id ?? '';

        [$next] = $screen->update(new PosterLoadedMsg('lib-a', $cardId, "POSTER-ANSI"));

        self::assertTrue($next->rail('lib-a')?->cards[0]->hasPoster());
        self::assertFalse($next->rail('lib-a')?->cards[1]->hasPoster());
    }

    public function testPosterForUnknownRailOrCardIsIgnored(): void
    {
        $screen = $this->withLibraryMedia('lib-a', 'Movies', 'm1');

        [$unknownRail] = $screen->update(new PosterLoadedMsg('nope', 'm1', 'X'));
        [$unknownCard] = $screen->update(new PosterLoadedMsg('lib-a', 'ghost', 'X'));

        self::assertFalse($unknownRail->rail('lib-a')?->cards[0]->hasPoster());
        self::assertFalse($unknownCard->rail('lib-a')?->cards[0]->hasPoster());
    }

    public function testVerticalAndHorizontalNavigation(): void
    {
        $screen = $this->withLibraryMedia('lib-a', 'Movies', 'm1', 'm2', 'm3')
            ->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies'), $this->library('lib-b', 'TV')]))[0];
        // Re-populate after the second LibrariesLoaded reset the rail map.
        $screen = $screen->update(new LibraryMediaLoadedMsg('lib-a', $this->page('m1', 'm2', 'm3')))[0];

        self::assertSame(0, $screen->railCursor());

        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->railCursor());

        [$clamped] = $down->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $clamped->railCursor(), 'clamped at the last rail');

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->railCursor());

        // Horizontal moves the focused rail's cursor.
        [$right] = $up->update(new KeyMsg(KeyType::Right));
        self::assertSame(1, $right->rail('lib-a')?->cursor);
    }

    public function testUpdateDoesNotMutateTheOriginalScreen(): void
    {
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('a', 'A'),
            $this->library('b', 'B'),
        ]))[0];
        self::assertSame(0, $screen->railCursor());

        // Deriving a moved copy must leave the source screen untouched — the
        // clone-mutate immutability contract this screen now relies on.
        [$moved] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $moved->railCursor());
        self::assertSame(0, $screen->railCursor(), 'the original screen is unchanged');
    }

    public function testQuitKeys(): void
    {
        [, $q] = $this->screen()->update(new KeyMsg(KeyType::Char, 'q'));
        [, $esc] = $this->screen()->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(QuitMsg::class, $q());
        self::assertInstanceOf(QuitMsg::class, $esc());
    }

    public function testEnterOnLibraryRailOpensThatLibrary(): void
    {
        $screen = $this->withLibraryMedia('lib-a', 'Movies', 'm1', 'm2');

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('lib-a', $msg->libraryId);
        self::assertSame('Movies', $msg->name);
    }

    public function testEnterOnContinueWatchingRailDoesNotOpenALibrary(): void
    {
        $entry = ContinueWatchingItem::fromArray(['media_item_id' => 'cw', 'name' => 'X', 'position_ticks' => 1, 'duration_ticks' => 2]);
        // Continue Watching prepends at index 0, where the cursor starts.
        [$screen] = $this->screen()
            ->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies')]))[0]
            ->update(new ContinueWatchingLoadedMsg([$entry]));
        self::assertSame('continue', $screen->railIds()[$screen->railCursor()]);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd, 'the continue-watching rail has no library grid to open');
    }

    public function testFailedLibrariesShowsError(): void
    {
        [$next] = $this->screen()->update(new LibrariesFailedMsg('Could not load your libraries.'));

        self::assertStringContainsString('Could not load your libraries.', $next->view());
    }

    public function testViewRendersPopulatedRail(): void
    {
        $view = $this->withLibraryMedia('lib-a', 'Movies', 'm1')->view();

        self::assertStringContainsString('Movies', $view);
    }

    public function testResizeKeepsRendering(): void
    {
        [$next] = $this->screen()->update(new WindowSizeMsg(100, 30));

        self::assertInstanceOf(BrowseScreen::class, $next);
        self::assertIsString($next->view());
    }

    // ---- running the async fetch/poster Cmds --------------------------

    public function testInitFetchesProduceLoadMessages(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['items' => []])                                                   // continue-watching
            ->json(200, ['libraries' => [['id' => 'lib-a', 'name' => 'Movies', 'type' => 'movie']]]);

        $msgs = $this->runBatch($this->screenWith($transport)->init());
        $types = array_map('get_class', $msgs);

        self::assertContains(ContinueWatchingLoadedMsg::class, $types);
        self::assertContains(LibrariesLoadedMsg::class, $types);
    }

    public function testInitAuthFailureProducesSessionExpired(): void
    {
        // No token set → each call 401s with no refresh → AuthError.
        $transport = (new FakeTransport())
            ->json(401, ['error' => 'Unauthorized'])
            ->json(401, ['error' => 'Unauthorized']);

        $msgs = $this->runBatch($this->screenWith($transport)->init());

        self::assertNotEmpty($msgs);
        foreach ($msgs as $msg) {
            self::assertInstanceOf(SessionExpiredMsg::class, $msg);
        }
    }

    public function testLibraryMediaFetchSuccess(): void
    {
        $transport = (new FakeTransport())->json(200, ['items' => [['id' => 'm1', 'name' => 'M', 'type' => 'movie']], 'total' => 1, 'limit' => 18, 'offset' => 0]);
        [, $cmd] = $this->screenWith($transport)->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies')]));

        $msgs = $this->runBatch($cmd);

        self::assertInstanceOf(LibraryMediaLoadedMsg::class, $msgs[0]);
        self::assertSame('lib-a', $msgs[0]->libraryId);
    }

    public function testLibraryMediaFetchAuthFailureProducesSessionExpired(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        [, $cmd] = $this->screenWith($transport)->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies')]));

        $msgs = $this->runBatch($cmd);

        self::assertInstanceOf(SessionExpiredMsg::class, $msgs[0]);
    }

    public function testPosterCmdRendersAndProducesPosterLoadedMsg(): void
    {
        $port = $this->startPosterServer();
        $loader = new PosterLoader(Mosaic::halfBlock());
        $page = MediaPage::fromArray(['items' => [['id' => 'm1', 'name' => 'M', 'type' => 'movie', 'poster_url' => "http://127.0.0.1:{$port}/p.png"]], 'total' => 1, 'limit' => 18, 'offset' => 0]);

        $screen = $this->screenWith(new FakeTransport(), $loader)->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies')]))[0];
        [, $posterCmd] = $screen->update(new LibraryMediaLoadedMsg('lib-a', $page));

        $msgs = $this->runBatch($posterCmd);

        self::assertInstanceOf(PosterLoadedMsg::class, $msgs[0]);
        self::assertSame('lib-a', $msgs[0]->railId);
        self::assertSame('m1', $msgs[0]->cardId);
        self::assertNotSame('', $msgs[0]->ansi);
    }

    public function testPosterCmdFailureIsSilentlyDropped(): void
    {
        // Grab then release a port so the connection is reliably refused.
        $probe = new SocketServer('127.0.0.1:0');
        $port = (int) parse_url((string) $probe->getAddress(), PHP_URL_PORT);
        $probe->close();

        $page = MediaPage::fromArray(['items' => [['id' => 'm1', 'name' => 'M', 'type' => 'movie', 'poster_url' => "http://127.0.0.1:{$port}/nope.png"]], 'total' => 1, 'limit' => 18, 'offset' => 0]);
        $screen = $this->screen()->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies')]))[0];
        [, $posterCmd] = $screen->update(new LibraryMediaLoadedMsg('lib-a', $page));

        self::assertSame([], $this->runBatch($posterCmd), 'a broken poster yields no Msg');
    }

    /**
     * Run a Cmd::batch (or single Cmd), resolving async children, and collect
     * the non-null Msgs they produce.
     *
     * @return list<Msg>
     */
    private function runBatch(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }

        $result = $cmd();
        $children = $result instanceof BatchMsg ? $result->cmds : [$cmd];

        $msgs = [];
        foreach ($children as $child) {
            $msg = $this->runCmd($child);
            if ($msg !== null) {
                $msgs[] = $msg;
            }
        }

        return $msgs;
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
        // Cancelling the safety timer on settle is essential: otherwise the
        // loop's stream_select stays blocked on that far-future timer for the
        // whole timeout after stop() when there's no other stream activity.
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

    /** Build a screen with one library loaded and its media populated. */
    private function withLibraryMedia(string $libId, string $name, string ...$itemIds): BrowseScreen
    {
        $screen = $this->screen()->update(new LibrariesLoadedMsg([$this->library($libId, $name)]))[0];
        $result = $screen->update(new LibraryMediaLoadedMsg($libId, $this->page(...$itemIds)))[0];
        self::assertInstanceOf(BrowseScreen::class, $result);

        return $result;
    }
}

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
use Phlix\Console\Msg\OpenDetailMsg;
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

    private function screenWith(FakeTransport $transport, ?PosterLoader $posters = null, string $base = 'https://srv'): BrowseScreen
    {
        $api = new ApiClient($base, $transport);

        return new BrowseScreen(
            AuthUser::fromArray(['id' => 'u', 'username' => 'joe', 'display_name' => 'Joe Huss']),
            new LibrariesStore($api),
            new MediaStore($api),
            $posters ?? new PosterLoader(Mosaic::halfBlock()),
            $base,
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

    public function testContinueWatchingDeduplicatesByMediaItem(): void
    {
        // The same title watched across several sessions/devices can arrive more
        // than once; only one card per media item should appear (the first, most
        // recent occurrence) so the rail isn't full of duplicates.
        $meta = ['poster_url' => 'https://p/cw.jpg'];
        $dup1 = ContinueWatchingItem::fromArray(['media_item_id' => 'cw1', 'name' => 'Show', 'position_ticks' => 7, 'duration_ticks' => 10, 'metadata' => $meta]);
        $dup2 = ContinueWatchingItem::fromArray(['media_item_id' => 'cw1', 'name' => 'Show', 'position_ticks' => 3, 'duration_ticks' => 10, 'metadata' => $meta]);
        $other = ContinueWatchingItem::fromArray(['media_item_id' => 'cw2', 'name' => 'Other', 'position_ticks' => 1, 'duration_ticks' => 10, 'metadata' => $meta]);

        [$next] = $this->screen()->update(new ContinueWatchingLoadedMsg([$dup1, $dup2, $other]));

        $cards = $next->rail('continue')?->cards ?? [];
        self::assertCount(2, $cards, 'duplicate media items collapse to one card each');
        self::assertSame(['cw1', 'cw2'], array_map(static fn ($c) => $c->id, $cards));
        // The kept card is the first (most recent) occurrence — 70% progress.
        self::assertEqualsWithDelta(0.7, $cards[0]->progress, 0.001);
    }

    public function testEmptyContinueWatchingAddsNoRail(): void
    {
        [$next] = $this->screen()->update(new ContinueWatchingLoadedMsg([]));

        self::assertSame([], $next->railIds());
    }

    public function testContinueWatchingWithNoValidItemsAddsNoRail(): void
    {
        // Non-ContinueWatchingItem entries are skipped; if none remain, no rail.
        [$next] = $this->screen()->update(new ContinueWatchingLoadedMsg(['garbage']));

        self::assertSame([], $next->railIds());
        self::assertNull($next->rail('continue'));
    }

    public function testNonLibraryEntriesAreSkipped(): void
    {
        [$next] = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('a', 'A'),
            'not-a-library',
            $this->library('b', 'B'),
        ]));

        self::assertSame(['a', 'b'], $next->railIds());
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

    // ---- sidebar + focus ring -----------------------------------------

    public function testDefaultFocusIsTheRails(): void
    {
        self::assertSame('rails', $this->screen()->focusedRegion());
    }

    public function testTabSwitchesFocusBetweenRailsAndSidebar(): void
    {
        $screen = $this->screen();
        self::assertFalse($screen->sidebar()->focused);

        [$toSidebar] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertSame('sidebar', $toSidebar->focusedRegion());
        self::assertTrue($toSidebar->sidebar()->focused, 'the sidebar accent follows the focus ring');

        [$backToRails] = $toSidebar->update(new KeyMsg(KeyType::Tab));
        self::assertSame('rails', $backToRails->focusedRegion());
        self::assertFalse($backToRails->sidebar()->focused);
    }

    public function testShiftTabSwitchesFocusBackwards(): void
    {
        // Two regions, so Shift-Tab from the rails also lands on the sidebar.
        [$next] = $this->screen()->update(new KeyMsg(KeyType::Tab, shift: true));
        self::assertSame('sidebar', $next->focusedRegion());
    }

    public function testLibrariesPopulateTheSidebar(): void
    {
        [$next] = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('lib-a', 'Movies'),
            $this->library('lib-b', 'TV'),
        ]));

        self::assertFalse($next->sidebar()->isEmpty());
        self::assertSame('lib-a', $next->sidebar()->selectedId());
    }

    public function testReloadingFewerLibrariesClampsTheSidebar(): void
    {
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('a', 'A'),
            $this->library('b', 'B'),
            $this->library('c', 'C'),
        ]))[0];
        $screen = $screen->update(new KeyMsg(KeyType::Tab))[0];
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame('c', $screen->sidebar()->selectedId());

        [$reloaded] = $screen->update(new LibrariesLoadedMsg([$this->library('a', 'A')]));
        self::assertSame('a', $reloaded->sidebar()->selectedId(), 'sidebar selection clamped into the smaller set');
    }

    public function testSidebarFocusedArrowsMoveTheSidebarNotTheRails(): void
    {
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('lib-a', 'Movies'),
            $this->library('lib-b', 'TV'),
        ]))[0];

        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));   // focus the sidebar
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));  // move the sidebar

        self::assertSame('lib-b', $screen->sidebar()->selectedId());
        self::assertSame(0, $screen->railCursor(), 'the rails cursor is untouched while the sidebar has focus');
    }

    public function testSidebarEnterOpensTheSelectedLibrary(): void
    {
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('lib-a', 'Movies'),
            $this->library('lib-b', 'TV'),
        ]))[0];

        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('lib-b', $msg->libraryId);
        self::assertSame('TV', $msg->name);
    }

    public function testSidebarEnterOnAMusicLibraryCarriesTheMusicType(): void
    {
        // A music-typed library threads its type into the OpenLibraryMsg so the
        // App can branch to the MusicScreen instead of the poster grid.
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            Library::fromArray(['id' => 'lib-mov', 'name' => 'Movies', 'type' => 'movie']),
            Library::fromArray(['id' => 'lib-mus', 'name' => 'Tunes', 'type' => 'music']),
        ]))[0];

        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));   // focus the sidebar
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));  // select the music library
        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('lib-mus', $msg->libraryId);
        self::assertSame('Tunes', $msg->name);
        self::assertSame('music', $msg->type);
    }

    public function testSidebarEnterOnANonMusicLibraryCarriesItsType(): void
    {
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            Library::fromArray(['id' => 'lib-mov', 'name' => 'Movies', 'type' => 'movie']),
        ]))[0];

        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));
        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('movie', $msg->type, 'movie-typed libraries keep their type too');
    }

    public function testSidebarEnterOnAnAudiobookLibraryCarriesTheAudiobookType(): void
    {
        // An audiobook-typed library threads its type into the OpenLibraryMsg so
        // the App branches to the AudiobooksScreen — the existing generic type
        // threading covers audiobooks with no BrowseScreen change.
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            Library::fromArray(['id' => 'lib-mov', 'name' => 'Movies', 'type' => 'movie']),
            Library::fromArray(['id' => 'lib-ab', 'name' => 'Listens', 'type' => 'audiobook']),
        ]))[0];

        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));   // focus the sidebar
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));  // select the audiobook library
        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('lib-ab', $msg->libraryId);
        self::assertSame('Listens', $msg->name);
        self::assertSame('audiobook', $msg->type);
    }

    public function testSidebarEnterOnAPhotoLibraryCarriesThePhotoType(): void
    {
        // A photo-typed library threads its type into the OpenLibraryMsg so the
        // App branches to the PhotosScreen — the existing generic type threading
        // covers photos with no BrowseScreen change.
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            Library::fromArray(['id' => 'lib-mov', 'name' => 'Movies', 'type' => 'movie']),
            Library::fromArray(['id' => 'lib-ph', 'name' => 'Snaps', 'type' => 'photo']),
        ]))[0];

        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));   // focus the sidebar
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));  // select the photo library
        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('lib-ph', $msg->libraryId);
        self::assertSame('Snaps', $msg->name);
        self::assertSame('photo', $msg->type);
    }

    public function testSidebarEnterThreadsTheLibraryItemCountIntoTheOpenMessage(): void
    {
        // A book-typed library's item count is threaded into OpenLibraryMsg so
        // the App can seed the BooksScreen grid total (the /books endpoint sends
        // no total).
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            Library::fromArray(['id' => 'lib-books', 'name' => 'Reads', 'type' => 'book', 'item_count' => 33]),
        ]))[0];

        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));   // focus the sidebar
        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenLibraryMsg::class, $msg);
        self::assertSame('lib-books', $msg->libraryId);
        self::assertSame('book', $msg->type);
        self::assertSame(33, $msg->itemCount, 'the library item count is carried for the grid total');
    }

    public function testSidebarEnterWithNoLibrariesDoesNothing(): void
    {
        [$screen] = $this->screen()->update(new KeyMsg(KeyType::Tab));   // sidebar focus, but empty
        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd);
        self::assertSame('sidebar', $next->focusedRegion());
    }

    public function testFocusSwitchIsReflectedInTheView(): void
    {
        $screen = $this->screen()->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies')]))[0];

        // (sugar-boxer collapses the hint's alignment spaces, so key off the
        // distinguishing word "menu", which only the rails-focused hint shows.)
        $railsView = $screen->view();
        self::assertStringContainsString('Libraries', $railsView, 'the sidebar is shown beside the rails');
        self::assertStringContainsString('menu', $railsView, 'the rails-focused hint offers Tab → menu');

        [$sidebar] = $screen->update(new KeyMsg(KeyType::Tab));
        $sidebarView = $sidebar->view();
        self::assertStringNotContainsString('menu', $sidebarView, 'the hint switched when focus moved to the sidebar');
        self::assertNotSame($railsView, $sidebarView, 'moving focus visibly changes the view');
    }

    public function testSidebarUpMovesTheSelectionBack(): void
    {
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('a', 'A'),
            $this->library('b', 'B'),
        ]))[0];

        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));
        [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame('b', $screen->sidebar()->selectedId());

        [$screen] = $screen->update(new KeyMsg(KeyType::Up));
        self::assertSame('a', $screen->sidebar()->selectedId());
    }

    public function testSidebarFocusedIgnoresHorizontalKeys(): void
    {
        $screen = $this->screen()->update(new LibrariesLoadedMsg([
            $this->library('a', 'A'),
            $this->library('b', 'B'),
        ]))[0];
        [$screen] = $screen->update(new KeyMsg(KeyType::Tab));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Left));

        self::assertNull($cmd);
        self::assertSame('a', $next->sidebar()->selectedId(), '←/→ do nothing in the vertical sidebar');
    }

    public function testArrowWithNoRailsIsANoOp(): void
    {
        // Rails focused by default, nothing loaded yet.
        [$next, $cmd] = $this->screen()->update(new KeyMsg(KeyType::Down));

        self::assertNull($cmd);
        self::assertSame(0, $next->railCursor());
    }

    public function testUnhandledRailsKeyIsIgnored(): void
    {
        $screen = $this->withLibraryMedia('lib-a', 'Movies', 'm1');

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        self::assertNull($cmd);
        self::assertSame('lib-a', $next->railIds()[$next->railCursor()]);
    }

    public function testUnhandledMessageIsIgnored(): void
    {
        // A Msg this screen does not handle (it's emitted, not consumed, here).
        [$next, $cmd] = $this->screen()->update(new SessionExpiredMsg('not handled here'));

        self::assertInstanceOf(BrowseScreen::class, $next);
        self::assertNull($cmd);
    }

    public function testPosterLoadedFillsTheContinueRailCard(): void
    {
        $entry = ContinueWatchingItem::fromArray([
            'media_item_id' => 'cw1', 'name' => 'Show', 'position_ticks' => 3, 'duration_ticks' => 10,
            'metadata' => ['poster_url' => 'https://p/cw.jpg'],
        ]);
        [$screen] = $this->screen()->update(new ContinueWatchingLoadedMsg([$entry]));
        $cardId = $screen->rail('continue')?->cards[0]->id ?? '';

        [$next] = $screen->update(new PosterLoadedMsg('continue', $cardId, 'POSTER-ANSI'));

        self::assertTrue($next->rail('continue')?->cards[0]->hasPoster());
    }

    public function testNavigatingManyRailsScrollsBothWays(): void
    {
        $libs = [];
        for ($i = 0; $i < 6; $i++) {
            $libs[] = $this->library("l{$i}", "Lib {$i}");
        }
        $screen = $this->screen()->update(new LibrariesLoadedMsg($libs))[0];

        // Down past the visible window scrolls the rails to follow the cursor.
        for ($i = 0; $i < 5; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame(5, $screen->railCursor());
        self::assertIsString($screen->view());

        // Back up to the top scrolls the other way.
        for ($i = 0; $i < 5; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Up));
        }
        self::assertSame(0, $screen->railCursor());
        self::assertIsString($screen->view());
    }

    public function testQuitKeys(): void
    {
        [, $q] = $this->screen()->update(new KeyMsg(KeyType::Char, 'q'));
        [, $esc] = $this->screen()->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(QuitMsg::class, $q());
        self::assertInstanceOf(QuitMsg::class, $esc());
    }

    public function testEnterOnALibraryRailOpensTheFocusedCardDetail(): void
    {
        $screen = $this->withLibraryMedia('lib-a', 'Movies', 'm1', 'm2');

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        // A poster opens the item's detail (the sidebar opens the whole library);
        // the cursor starts on the first card.
        self::assertInstanceOf(OpenDetailMsg::class, $msg);
        self::assertSame('m1', $msg->id);
        self::assertSame('Item m1', $msg->name);
    }

    public function testEnterOnContinueWatchingRailOpensThatItemDetail(): void
    {
        $entry = ContinueWatchingItem::fromArray(['media_item_id' => 'cw', 'name' => 'X', 'position_ticks' => 1, 'duration_ticks' => 2]);
        // Continue Watching prepends at index 0, where the cursor starts.
        [$screen] = $this->screen()
            ->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies')]))[0]
            ->update(new ContinueWatchingLoadedMsg([$entry]));
        self::assertSame('continue', $screen->railIds()[$screen->railCursor()]);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $cmd?->__invoke();

        self::assertInstanceOf(OpenDetailMsg::class, $msg, 'a continue-watching card opens its detail');
        self::assertSame('cw', $msg->id);
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
     * An empty (or null) posterUrl is the ONLY case skipped by loadPostersIn:
     * after resolveUrl it stays empty, so no poster load Cmd is scheduled (a
     * crash-free skip — no "URL scheme unknown" from the loader).
     */
    public function testEmptyStringPosterUrlIsSkippedAndDoesNotCrash(): void
    {
        self::assertSame(0, $this->scheduledPosterLoads($this->posterCmdFor('')), 'an empty posterUrl schedules no poster load');
    }

    /**
     * A relative URL (no scheme, e.g. /poster.png) is resolved to an absolute URL
     * (baseUrl + path) and IS loaded — it is NOT dropped. The base IS the local
     * poster server, so the resolved URL genuinely loads and yields a poster msg,
     * proving the relative path was resolved against the base (an unresolved
     * "/poster.png" has no host and could not load).
     */
    public function testRelativeUrlPosterIsResolvedAndLoaded(): void
    {
        $port = $this->startPosterServer();
        $msgs = $this->runBatch($this->posterCmdFor('/poster.png', "http://127.0.0.1:{$port}"));

        self::assertCount(1, $msgs, 'the relative posterUrl is resolved against the base and loaded');
        self::assertInstanceOf(PosterLoadedMsg::class, $msgs[0]);
    }

    /**
     * A scheme-less string (e.g. not-a-valid-url) is treated as a relative path:
     * resolveUrl prefixes the base, so the resolved URL is http(s) and a load IS
     * scheduled against the base (it is not silently dropped).
     */
    public function testMalformedUrlPosterIsResolvedAgainstBase(): void
    {
        self::assertSame(1, $this->scheduledPosterLoads($this->posterCmdFor('not-a-valid-url')), 'a scheme-less posterUrl is base-prefixed and a load is scheduled');
    }

    /**
     * A non-http(s) scheme (e.g. ftp://…) does not match the absolute-URL guard,
     * so resolveUrl treats it as a relative path and prefixes the base; the
     * resolved URL is http(s) and a load IS scheduled against the base.
     */
    public function testNonHttpSchemePosterIsResolvedAgainstBase(): void
    {
        self::assertSame(1, $this->scheduledPosterLoads($this->posterCmdFor('ftp://cdn.example.com/file.jpg')), 'a non-http(s) posterUrl is base-prefixed and a load is scheduled');
    }

    /** Populate a one-item rail and return the poster-load Cmd for that item's poster_url. */
    private function posterCmdFor(string $posterUrl, string $base = 'https://srv'): ?\Closure
    {
        $page = MediaPage::fromArray(['items' => [['id' => 'm1', 'name' => 'M', 'type' => 'movie', 'poster_url' => $posterUrl]], 'total' => 1, 'limit' => 18, 'offset' => 0]);
        $screen = $this->screenWith(new FakeTransport(), new PosterLoader(Mosaic::halfBlock()), $base)
            ->update(new LibrariesLoadedMsg([$this->library('lib-a', 'Movies')]))[0];

        return $screen->update(new LibraryMediaLoadedMsg('lib-a', $page))[1];
    }

    /** Count the poster-load Cmds a (possibly null) batch schedules, without running them. */
    private function scheduledPosterLoads(?\Closure $cmd): int
    {
        if ($cmd === null) {
            return 0;
        }
        $result = $cmd();

        return $result instanceof BatchMsg ? count($result->cmds) : 1;
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

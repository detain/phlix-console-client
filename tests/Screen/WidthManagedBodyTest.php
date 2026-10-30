<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\LibrariesFailedMsg;
use Phlix\Console\Msg\LibrariesLoadedMsg;
use Phlix\Console\Msg\LibraryFailedMsg;
use Phlix\Console\Msg\LibraryMediaLoadedMsg;
use Phlix\Console\Msg\SearchDebouncedMsg;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Screen\BrowseScreen;
use Phlix\Console\Screen\LibraryScreen;
use Phlix\Console\Screen\SearchScreen;
use Phlix\Console\Store\FacetsStore;
use Phlix\Console\Store\FavoritesStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Msg\MediaRangeLoadedMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Image\Mosaic;

/**
 * M-CONSOLE-70 + M-CONSOLE-71 [review console__uxdesign]: body strings built
 * from unbounded user/server text (typed queries, wire error reasons, display
 * names, library titles) must be display-width managed — unbounded they wrap
 * inside the content panel and silently steal rows from the grid viewport —
 * and the Browse sidebar+rails join must COLLAPSE below the width it can no
 * longer pay for instead of forcing an overflowing join.
 *
 * The pins are deliberately written against the ellipsis MARKER ('…'): the
 * panel's own silent clip never emits one, so a green test here proves the
 * app truncated on purpose, not that the renderer swallowed the tail.
 */
final class WidthManagedBodyTest extends TestCase
{
    /** 34 cells per repetition — ten of them are 340 cells, any panel's match. */
    private const LONG_REASON = 'extremely-long-unbounded-server-text-';

    // ---- M-CONSOLE-70: Search -------------------------------------------

    public function testATypedQueryLongerThanThePanelIsEllipsisedWithItsCursor(): void
    {
        $screen = $this->searchScreen(new FakeTransport(), 40);

        $query = str_repeat('m', 60);
        foreach (mb_str_split($query) as $rune) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Char, $rune));
        }

        $view = $screen->view();
        $line = self::lineContaining($view, 'Search:');

        self::assertStringContainsString('…▏', $line, 'the cut is signalled and the ▏ cursor survives it');
        self::assertLessThanOrEqual(40, Width::string($line));
        self::assertStringNotContainsString($query, $view, 'the raw 60-cell query must never ride the panel');
    }

    public function testTheSearchErrorReasonIsEllipsisedToTheContentBudget(): void
    {
        [$screen] = $this->searchScreen(new FakeTransport(), 40)->update(new LibraryFailedMsg(self::longReason()));

        $view = $screen->view();
        $line = self::lineContaining($view, 'extremely');

        self::assertStringContainsString('…', $line, 'a clipped wire reason carries the marker');
        self::assertStringNotContainsString(self::longReason(), $view);
        self::assertEveryFrameLineFits(40, $view);
    }

    public function testTheNoResultsLineIsEllipsised(): void
    {
        $query = str_repeat('z', 60);
        $transport = (new FakeTransport())->json(200, ['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);
        $screen = $this->searchScreen($transport, 40);

        foreach (mb_str_split($query) as $rune) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$screen, $fetch] = $screen->update(new SearchDebouncedMsg(mb_strlen($query)));
        self::assertInstanceOf(\Closure::class, $fetch);
        $msg = $this->runCmd($fetch);
        self::assertInstanceOf(MediaRangeLoadedMsg::class, $msg);
        [$screen] = $screen->update($msg);

        $view = $screen->view();
        $line = self::lineContaining($view, 'No results');

        self::assertStringContainsString('…', $line);
        self::assertStringNotContainsString($query, $view);
    }

    // ---- M-CONSOLE-70: Library / Browse / Chrome header ------------------

    public function testTheLibraryTitleRidesTheHeaderBudget(): void
    {
        $view = $this->libraryScreen(str_repeat('L', 60))->view();
        $line = self::lineContaining($view, 'Phlix');

        self::assertStringContainsString('…', $line, 'a 60-cell library name is ellipsised, not pushed');
        self::assertStringNotContainsString(str_repeat('L', 60), $view);
        self::assertEveryFrameLineFits(40, $view);
    }

    public function testTheLibraryErrorIsEllipsised(): void
    {
        [$screen] = $this->libraryScreen('Movies')->update(new LibraryFailedMsg(self::longReason()));

        $view = $screen->view();
        $line = self::lineContaining($view, 'extremely');

        self::assertStringContainsString('…', $line);
        self::assertStringNotContainsString(self::longReason(), $view);
        self::assertEveryFrameLineFits(40, $view);
    }

    public function testTheWelcomeNameIsEllipsised(): void
    {
        $screen = $this->browseScreen(str_repeat('N', 70), 40);

        $view = $screen->view();
        $line = self::lineContaining($view, 'Welcome');

        self::assertStringContainsString('…', $line);
        self::assertStringNotContainsString(str_repeat('N', 70), $view);
        self::assertEveryFrameLineFits(40, $view);
    }

    public function testTheLibrariesErrorIsEllipsised(): void
    {
        [$screen] = $this->browseScreen('Joe Huss', 40)->update(new LibrariesFailedMsg(self::longReason()));

        $view = $screen->view();
        $line = self::lineContaining($view, 'extremely');

        self::assertStringContainsString('…', $line);
        self::assertStringNotContainsString(self::longReason(), $view);
        self::assertEveryFrameLineFits(40, $view);
    }

    public function testAHeaderTitleWithoutCrumbsHonoursTheBreadcrumbBudget(): void
    {
        $title = str_repeat('T', 90);

        $view = Chrome::frame($title, 'body', 'hint', 48, 12, [], Theme::nocturne());
        $line = self::lineContaining($view, 'Phlix');

        self::assertStringContainsString('…', $line, 'the bare-title path pays the same law the crumb path always did');
        self::assertStringNotContainsString($title, $view);
        self::assertEveryFrameLineFits(48, $view);
    }

    // ---- M-CONSOLE-71: Browse collapse -----------------------------------

    public function testNarrowBrowseCollapsesTheRailsAndShowsTheLibraryListAlone(): void
    {
        $screen = $this->browseWithLibraryMedia(41);

        $view = $screen->view();

        self::assertStringContainsString('Movies', $view, 'the library list (the sidebar) still shows');
        self::assertStringNotContainsString(
            '░',
            $view,
            'no rail poster art renders below the width the join cannot pay'
        );
        self::assertStringNotContainsString('Tab  rails', $view, 'the hint stops advertising a key that leads nowhere');
        self::assertEveryFrameLineFits(41, $view);
    }

    public function testWideBrowseKeepsTheRailsAndTheTabKey(): void
    {
        $view = $this->browseWithLibraryMedia(120)->view();

        self::assertStringContainsString('░', $view, 'the rails paint where they fit');
        self::assertStringContainsString('↑↓  rails', $view, 'the rails hint carries the rails keys');
    }

    public function testTabIsInertWhileTheRailsAreCollapsed(): void
    {
        $screen = $this->browseWithLibraryMedia(41);

        [$next] = $screen->update(new KeyMsg(KeyType::Tab));

        self::assertSame($screen, $next, 'nothing to tab to — the screen is returned untouched');
    }

    public function testTabMovesFocusBackToTheSidebarWhenTheRailsFit(): void
    {
        $screen = $this->browseWithLibraryMedia(120);

        // The rails hold focus by default: the rails hint (with its ←→ item
        // keys) is on screen before any tabbing.
        self::assertStringContainsString('←→  items', $screen->view());

        [$next] = $screen->update(new KeyMsg(KeyType::Tab));

        self::assertNotSame($screen, $next);
        self::assertStringContainsString('Tab  rails', $next->view(), 'the sidebar hint hands the key back');
    }

    // ---- builders --------------------------------------------------------

    private static function longReason(): string
    {
        return str_repeat(self::LONG_REASON, 10);
    }

    /** The one frame line carrying $needle (the panel may add border/padding). */
    private static function lineContaining(string $view, string $needle): string
    {
        foreach (explode("\n", $view) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        self::fail("no frame line contains '{$needle}'");
    }

    private static function assertEveryFrameLineFits(int $cols, string $view): void
    {
        foreach (explode("\n", $view) as $index => $line) {
            self::assertLessThanOrEqual(
                $cols,
                Width::string($line),
                "frame line {$index} is " . Width::string($line) . " cells on a {$cols}-cell terminal: '{$line}'"
            );
        }
    }

    private function searchScreen(FakeTransport $transport, int $cols): SearchScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new SearchScreen(
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            $api->baseUrl(),
            cols: $cols,
            rows: 20,
        );
    }

    private function libraryScreen(string $name, int $cols = 40): LibraryScreen
    {
        $api = new ApiClient('https://srv', new FakeTransport());

        return new LibraryScreen(
            'lib-a',
            $name,
            new MediaStore($api),
            new FacetsStore(new ApiClient('https://srv', new FakeTransport())),
            new PosterLoader(Mosaic::halfBlock()),
            'https://srv',
            cols: $cols,
            rows: 20,
        );
    }

    private function browseScreen(string $displayName, int $cols): BrowseScreen
    {
        $api = new ApiClient('https://srv', new FakeTransport());

        return new BrowseScreen(
            AuthUser::fromArray(['id' => 'u', 'username' => 'joe', 'display_name' => $displayName]),
            new LibrariesStore($api),
            new MediaStore($api),
            new FavoritesStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            'https://srv',
            cols: $cols,
            rows: 30,
        );
    }

    /** A Browse screen whose rails are populated (sidebar + one media rail). */
    private function browseWithLibraryMedia(int $cols): BrowseScreen
    {
        $libraries = Library::fromArray(['id' => 'lib-a', 'name' => 'Movies', 'type' => 'movie']);
        $screen = $this->browseScreen('Joe Huss', $cols)
            ->update(new LibrariesLoadedMsg([$libraries]))[0];

        $page = MediaPage::fromArray([
            'items' => [
                ['id' => 'm1', 'name' => 'Item m1', 'type' => 'movie', 'poster_url' => 'https://p/m1.jpg'],
                ['id' => 'm2', 'name' => 'Item m2', 'type' => 'movie', 'poster_url' => 'https://p/m2.jpg'],
            ],
            'total' => 2,
            'limit' => 18,
            'offset' => 0,
        ]);

        return $screen->update(new LibraryMediaLoadedMsg('lib-a', $page))[0];
    }

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

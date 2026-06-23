<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Msg\AlbumsLoadedMsg;
use Phlix\Console\Msg\MusicFailedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenAlbumMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Screen\MusicScreen;
use Phlix\Console\Store\MusicStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

final class MusicScreenTest extends TestCase
{
    private function screenWith(FakeTransport $transport): MusicScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new MusicScreen(new MusicStore($api), cols: 120, rows: 40);
    }

    /** The `{ "albums": [ … ] }` envelope with two albums. */
    private function albumsResponse(): array
    {
        return ['albums' => [
            [
                'name' => 'Abbey Road',
                'artist' => 'The Beatles',
                'year' => 1969,
                'track_count' => 17,
                'tracks' => [],
            ],
            [
                'name' => 'Kind of Blue',
                'artist' => null,
                'year' => null,
                'track_count' => 5,
                'tracks' => [],
            ],
        ]];
    }

    /** Load albums into the screen (init → AlbumsLoadedMsg → update). */
    private function loaded(): MusicScreen
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->albumsResponse()));
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AlbumsLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    public function testInitFetchesAlbumsFromTheMusicEndpoint(): void
    {
        $transport = (new FakeTransport())->json(200, $this->albumsResponse());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AlbumsLoadedMsg::class, $msg);
        self::assertCount(2, $msg->albums);
        self::assertSame('Abbey Road', $msg->albums[0]->name);
        self::assertStringContainsString('/api/v1/music/albums', $transport->requestAt(0)['url']);
    }

    public function testLoadedRendersTheAlbumTable(): void
    {
        $loaded = $this->loaded();

        self::assertTrue($loaded->isLoaded());
        self::assertSame('Abbey Road', $loaded->selectedAlbum()?->name);

        $view = $loaded->view();
        self::assertStringContainsString('Album', $view);
        self::assertStringContainsString('Artist', $view);
        self::assertStringContainsString('Year', $view);
        self::assertStringContainsString('Tracks', $view);
        self::assertStringContainsString('Abbey Road', $view);
        self::assertStringContainsString('The Beatles', $view);
        self::assertStringContainsString('1969', $view, 'the year renders');
        self::assertStringContainsString('17', $view, 'the track count renders');
        self::assertStringContainsString('Kind of Blue', $view);
    }

    public function testNullArtistAndYearRenderAsDashes(): void
    {
        // The second album has a null artist and year → shown as an em dash.
        $view = $this->loaded()->view();

        self::assertStringContainsString('—', $view, 'missing artist/year is shown as a dash');
    }

    public function testLoadingViewBeforeAlbumsArrive(): void
    {
        $screen = $this->screenWith((new FakeTransport())->pending());

        $view = $screen->view();
        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading music', $view);
        self::assertStringContainsString('Music', $view, 'the title fills the header during load');
    }

    public function testEmptyLibraryShowsTheEmptyState(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['albums' => []]));
        $msg = $this->runCmd($screen->init());

        [$loaded] = $screen->update($msg);

        self::assertTrue($loaded->isLoaded());
        self::assertNull($loaded->selectedAlbum());
        self::assertStringContainsString('No albums', $loaded->view());
    }

    public function testFetchFailureShowsAnError(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('boom'));
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(MusicFailedMsg::class, $msg);

        [$failed] = $screen->update($msg);
        self::assertStringContainsString('Could not load', $failed->view());
        self::assertStringContainsString('Could not load', (string) $failed->error());
    }

    public function testAuthErrorBecomesSessionExpired(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'unauthorized']);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testDownAndUpMoveTheSelectionAndClamp(): void
    {
        $loaded = $this->loaded();
        self::assertSame(0, $loaded->selectedIndex());

        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());
        self::assertSame('Kind of Blue', $down->selectedAlbum()?->name);

        // Down again clamps at the last album (only two).
        [$clamped, $cmd] = $down->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $clamped->selectedIndex());
        self::assertSame($down, $clamped, 'a clamped move is a no-op');
        self::assertNull($cmd);

        // Up at the top clamps at 0.
        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex());
        [$topClamped] = $up->update(new KeyMsg(KeyType::Up));
        self::assertSame($up, $topClamped, 'up at the top is a no-op');
    }

    public function testEnterOpensTheSelectedAlbum(): void
    {
        $loaded = $this->loaded();
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));

        [$same, $cmd] = $down->update(new KeyMsg(KeyType::Enter));

        $msg = $cmd?->__invoke();
        self::assertInstanceOf(OpenAlbumMsg::class, $msg);
        self::assertSame('Kind of Blue', $msg->album->name);
        self::assertSame($down, $same, 'opening does not mutate the screen');
    }

    public function testEnterOnAnEmptyLibraryIsANoOp(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['albums' => []]));
        [$loaded] = $screen->update($this->runCmd($screen->init()));

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd, 'nothing to open');
    }

    public function testArrowsOnAnEmptyLibraryAreNoOps(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['albums' => []]));
        [$loaded] = $screen->update($this->runCmd($screen->init()));

        [$down, $downCmd] = $loaded->update(new KeyMsg(KeyType::Down));
        [$up, $upCmd] = $loaded->update(new KeyMsg(KeyType::Up));

        self::assertSame($loaded, $down);
        self::assertSame($loaded, $up);
        self::assertNull($downCmd);
        self::assertNull($upCmd);
    }

    public function testEscapeAndQNavigateBack(): void
    {
        $loaded = $this->loaded();

        [, $escCmd] = $loaded->update(new KeyMsg(KeyType::Escape));
        [, $qCmd] = $loaded->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertInstanceOf(NavigateBackMsg::class, $escCmd?->__invoke());
        self::assertInstanceOf(NavigateBackMsg::class, $qCmd?->__invoke());
    }

    public function testUnhandledKeyAndMessageAreNoOps(): void
    {
        $loaded = $this->loaded();

        [$afterKey, $keyCmd] = $loaded->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($loaded, $afterKey);
        self::assertNull($keyCmd);

        [$afterMsg, $msgCmd] = $loaded->update(new SessionExpiredMsg('ignored here'));
        self::assertSame($loaded, $afterMsg);
        self::assertNull($msgCmd);
    }

    public function testResizeStillRenders(): void
    {
        // A small window must still re-flow and render without error.
        [$small] = $this->loaded()->update(new WindowSizeMsg(60, 20));
        self::assertIsString($small->view());

        // At a comfortable size the album rows are visible after the re-flow.
        [$big] = $this->loaded()->update(new WindowSizeMsg(100, 40));
        self::assertStringContainsString('Abbey Road', $big->view());
    }

    public function testSelectionClampsWhenAReloadReturnsFewerAlbums(): void
    {
        // Select the second album, then a fresh (smaller) result lands.
        $loaded = $this->loaded();
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());

        [$reloaded] = $down->update(new AlbumsLoadedMsg([$loaded->selectedAlbum()]));

        self::assertSame(0, $reloaded->selectedIndex(), 'the cursor is clamped into the smaller list');
    }

    public function testBreadcrumbLabelAndWithCrumbs(): void
    {
        $loaded = $this->loaded();
        self::assertSame('Music', $loaded->crumbLabel());

        $withCrumbs = $loaded->withCrumbs(['Home', 'Music']);
        self::assertStringContainsString('Home', $withCrumbs->view());
        self::assertStringContainsString('›', $withCrumbs->view());
    }

    public function testAlbumsAccessorReturnsTheLoadedAlbums(): void
    {
        $loaded = $this->loaded();

        $albums = $loaded->albums();
        self::assertCount(2, $albums);
        self::assertSame('Abbey Road', $albums[0]->name);
        self::assertSame('Kind of Blue', $albums[1]->name);
    }

    // ---- harness (mirrors DetailScreenTest) ---------------------------

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

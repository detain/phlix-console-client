<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Msg\AudiobooksFailedMsg;
use Phlix\Console\Msg\AudiobooksLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenAudiobookMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Screen\AudiobooksScreen;
use Phlix\Console\Store\AudiobooksStore;
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

final class AudiobooksScreenTest extends TestCase
{
    private function screenWith(FakeTransport $transport, ?string $libraryId = 'lib-ab', string $name = 'Listens'): AudiobooksScreen
    {
        $api = new ApiClient('https://srv', $transport);

        return new AudiobooksScreen(new AudiobooksStore($api), $libraryId, $name, cols: 120, rows: 40);
    }

    /** The `{ "audiobooks": [ … ] }` envelope with two audiobooks. */
    private function audiobooksResponse(): array
    {
        return ['audiobooks' => [
            [
                'id' => 'ab1',
                'name' => 'dune.m4b',
                'metadata' => [
                    'title' => 'Dune',
                    'author' => 'Frank Herbert',
                    'narrator' => 'Scott Brick',
                    'duration_ms' => 75_600_000, // 21:00:00
                ],
            ],
            [
                'id' => 'ab2',
                'name' => 'unknown.m4b',
                'metadata' => [
                    'title' => 'Untitled Listen',
                    'author' => null,
                    'narrator' => null,
                    'duration_ms' => null,
                ],
            ],
        ]];
    }

    /** Load audiobooks into the screen (init → AudiobooksLoadedMsg → update). */
    private function loaded(): AudiobooksScreen
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->audiobooksResponse()));
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AudiobooksLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    public function testInitFetchesAudiobooksFromTheAudiobooksEndpoint(): void
    {
        $transport = (new FakeTransport())->json(200, $this->audiobooksResponse());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AudiobooksLoadedMsg::class, $msg);
        self::assertCount(2, $msg->audiobooks);
        self::assertSame('Dune', $msg->audiobooks[0]->title);
        self::assertStringContainsString('/api/v1/audiobooks', $transport->requestAt(0)['url']);
    }

    public function testInitScopesTheFetchToTheLibrary(): void
    {
        $transport = (new FakeTransport())->json(200, $this->audiobooksResponse());
        $this->runCmd($this->screenWith($transport, 'lib-ab')->init());

        self::assertStringContainsString('library_id=lib-ab', $transport->requestAt(0)['url']);
    }

    public function testLoadedRendersTheAudiobookTable(): void
    {
        $loaded = $this->loaded();

        self::assertTrue($loaded->isLoaded());
        self::assertSame('Dune', $loaded->selectedAudiobook()?->title);

        $view = $loaded->view();
        self::assertStringContainsString('Title', $view);
        self::assertStringContainsString('Author', $view);
        self::assertStringContainsString('Narrator', $view);
        self::assertStringContainsString('Duration', $view);
        self::assertStringContainsString('Dune', $view);
        self::assertStringContainsString('Frank Herbert', $view);
        self::assertStringContainsString('Scott Brick', $view);
        self::assertStringContainsString('21:00:00', $view, 'the duration renders as h:mm:ss');
        self::assertStringContainsString('Untitled Listen', $view);
    }

    public function testNullAuthorNarratorAndDurationRenderAsDashes(): void
    {
        // The second audiobook has a null author, narrator and duration → dashes.
        $view = $this->loaded()->view();

        self::assertStringContainsString('—', $view, 'missing author/narrator/duration is shown as a dash');
    }

    public function testTheSelectedAudiobookRowRendersReverseVideo(): void
    {
        // Selection is real ANSI reverse-video (sugar-table), not a plain cursor.
        $loaded = $this->loaded();

        self::assertTrue(self::hasReverse($this->lineContaining($loaded->view(), 'Dune')), 'the selected row is reversed');
        self::assertFalse(self::hasReverse($this->lineContaining($loaded->view(), 'Untitled Listen')), 'the unselected row is not reversed');

        // Move down: the highlight follows the selection to the second audiobook.
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertTrue(self::hasReverse($this->lineContaining($down->view(), 'Untitled Listen')));
        self::assertFalse(self::hasReverse($this->lineContaining($down->view(), 'Dune')));
    }

    /** True if a rendered line carries the SGR reverse attribute (7), however encoded. */
    private static function hasReverse(string $line): bool
    {
        return preg_match('/\e\[(?:[0-9;]*;)?7(?:;[0-9;]*)?m/', $line) === 1;
    }

    private function lineContaining(string $view, string $needle): string
    {
        foreach (explode("\n", $view) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }
        self::fail("no line contains [{$needle}]");
    }

    public function testLoadingViewBeforeAudiobooksArrive(): void
    {
        $screen = $this->screenWith((new FakeTransport())->pending());

        $view = $screen->view();
        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading audiobooks', $view);
        self::assertStringContainsString('Listens', $view, 'the library name fills the header during load');
    }

    public function testEmptyLibraryShowsTheEmptyState(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['audiobooks' => []]));
        $msg = $this->runCmd($screen->init());

        [$loaded] = $screen->update($msg);

        self::assertTrue($loaded->isLoaded());
        self::assertNull($loaded->selectedAudiobook());
        self::assertStringContainsString('No audiobooks', $loaded->view());
    }

    public function testFetchFailureShowsAnError(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('boom'));
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AudiobooksFailedMsg::class, $msg);

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
        self::assertSame('Untitled Listen', $down->selectedAudiobook()?->title);

        // Down again clamps at the last audiobook (only two).
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

    public function testEnterOpensTheSelectedAudiobook(): void
    {
        $loaded = $this->loaded();
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));

        [$same, $cmd] = $down->update(new KeyMsg(KeyType::Enter));

        $msg = $cmd?->__invoke();
        self::assertInstanceOf(OpenAudiobookMsg::class, $msg);
        self::assertSame('ab2', $msg->id);
        self::assertSame('Untitled Listen', $msg->title);
        self::assertSame($down, $same, 'opening does not mutate the screen');
    }

    public function testEnterOnAnEmptyLibraryIsANoOp(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['audiobooks' => []]));
        [$loaded] = $screen->update($this->runCmd($screen->init()));

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd, 'nothing to open');
    }

    public function testArrowsOnAnEmptyLibraryAreNoOps(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['audiobooks' => []]));
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
        [$small] = $this->loaded()->update(new WindowSizeMsg(60, 20));
        self::assertIsString($small->view());

        [$big] = $this->loaded()->update(new WindowSizeMsg(100, 40));
        self::assertStringContainsString('Dune', $big->view());
    }

    public function testSelectionClampsWhenAReloadReturnsFewerAudiobooks(): void
    {
        // Select the second audiobook, then a fresh (smaller) result lands.
        $loaded = $this->loaded();
        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());

        [$reloaded] = $down->update(new AudiobooksLoadedMsg([$loaded->selectedAudiobook()]));

        self::assertSame(0, $reloaded->selectedIndex(), 'the cursor is clamped into the smaller list');
    }

    public function testBreadcrumbLabelAndWithCrumbs(): void
    {
        $loaded = $this->loaded();
        self::assertSame('Listens', $loaded->crumbLabel());

        $withCrumbs = $loaded->withCrumbs(['Home', 'Listens']);
        self::assertStringContainsString('Home', $withCrumbs->view());
        self::assertStringContainsString('›', $withCrumbs->view());
    }

    public function testBreadcrumbFallsBackToAudiobooksWhenNameEmpty(): void
    {
        $screen = $this->screenWith((new FakeTransport())->pending(), 'lib-ab', '');

        self::assertSame('Audiobooks', $screen->crumbLabel());
        self::assertStringContainsString('Audiobooks', $screen->view(), 'the fallback title fills the header');
    }

    public function testAudiobooksAccessorReturnsTheLoadedAudiobooks(): void
    {
        $loaded = $this->loaded();

        $audiobooks = $loaded->audiobooks();
        self::assertCount(2, $audiobooks);
        self::assertSame('Dune', $audiobooks[0]->title);
        self::assertSame('Untitled Listen', $audiobooks[1]->title);
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
        $promise->then(
            function ($v) use (&$state): void {
                $state['value'] = $v;
                $state['done'] = true;
                Loop::stop();
            },
            function ($e) use (&$state): void {
                $state['error'] = $e;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if ($state['done']) {
            // The promise settled synchronously (the AudiobooksStore wraps the
            // sync FakeTransport in a Deferred). React may still have enqueued the
            // Deferred's handler on the loop's futureTick queue — flush it with a
            // single immediate tick so no residual work leaks into a later test's
            // Loop::run(); a futureTick stop returns at once (no blocking wait).
            Loop::futureTick(static fn () => Loop::stop());
            Loop::run();
        } else {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
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

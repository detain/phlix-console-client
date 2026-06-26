<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\LogFile;
use Phlix\Console\Api\Dto\Admin\LogTail;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminLogFailedMsg;
use Phlix\Console\Msg\AdminLogFilesLoadedMsg;
use Phlix\Console\Msg\AdminLogTailLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Screen\AdminLogsScreen;
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

final class AdminLogsScreenTest extends TestCase
{
    private function envelope(mixed $data): array
    {
        return ['success' => true, 'data' => $data, 'count' => is_array($data) ? count($data) : 1];
    }

    private function fileListPayload(): array
    {
        return $this->envelope([
            'files' => [
                ['name' => 'app.log', 'size' => 4096, 'modified_at' => '2026-06-26T12:00:00-04:00'],
                ['name' => 'error.log', 'size' => 128, 'modified_at' => '2026-06-25T09:00:00-04:00'],
            ],
        ]);
    }

    private function tailPayload(): array
    {
        return $this->envelope([
            'file' => 'app.log',
            'lines' => array_map(static fn (int $n): string => "log line {$n}", range(1, 60)),
            'truncated' => true,
        ]);
    }

    private function allTailPayload(): array
    {
        return $this->envelope([
            'files' => ['app.log', 'error.log'],
            'lines' => ['app.log    started', 'error.log  oops'],
            'truncated' => false,
        ]);
    }

    private function screenWith(FakeTransport $transport): AdminLogsScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminLogsScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** Drive init → the loaded/failed Msg, then apply it. */
    private function withFiles(FakeTransport $transport): AdminLogsScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertNotNull($msg);

        return $screen->update($msg)[0];
    }

    public function testInitFetchesTheFileList(): void
    {
        $transport = (new FakeTransport())->json(200, $this->fileListPayload());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminLogFilesLoadedMsg::class, $msg);
        self::assertCount(2, $msg->files);
        self::assertContainsOnlyInstancesOf(LogFile::class, $msg->files);
    }

    public function testLoadingStateBeforeFiles(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->fileListPayload()));

        self::assertFalse($screen->filesLoaded());
        self::assertStringContainsString('Loading logs', $screen->view());
    }

    public function testRendersTheFileListWithTheAllLogsEntry(): void
    {
        $screen = $this->withFiles((new FakeTransport())->json(200, $this->fileListPayload()));

        self::assertTrue($screen->filesLoaded());
        self::assertCount(2, $screen->fileList());

        $view = $screen->view();
        self::assertStringContainsString('All logs (merged)', $view);
        self::assertStringContainsString('app.log', $view);
        self::assertStringContainsString('error.log', $view);
        self::assertStringContainsString('Select a log to tail it', $view);
    }

    public function testListFetchFailureShowsTheErrorAndRetry(): void
    {
        $screen = $this->withFiles((new FakeTransport())->json(500, ['error' => 'boom']));

        self::assertFalse($screen->filesLoaded());
        self::assertNotNull($screen->error());
        $view = $screen->view();
        self::assertStringContainsString('Could not load the log files', $view);
        self::assertStringContainsString('Press r to retry', $view);
    }

    public function testDownAndUpMoveTheSelectionAndClamp(): void
    {
        $screen = $this->withFiles((new FakeTransport())->json(200, $this->fileListPayload()));
        self::assertSame(0, $screen->selectedIndex());

        // 1 "all logs" + 2 files = 3 entries.
        [$d1] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $d1->selectedIndex());
        [$d2] = $d1->update(new KeyMsg(KeyType::Down));
        self::assertSame(2, $d2->selectedIndex());
        // Down at the bottom clamps (same instance).
        [$d3] = $d2->update(new KeyMsg(KeyType::Down));
        self::assertSame(2, $d3->selectedIndex());

        [$up] = $d3->update(new KeyMsg(KeyType::Up));
        self::assertSame(1, $up->selectedIndex());
    }

    public function testEnterOnAFileTailsItIntoTheViewport(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->fileListPayload())
            ->json(200, $this->tailPayload());
        $screen = $this->withFiles($transport);

        // Move to app.log (index 1) and open it.
        [$onApp] = $screen->update(new KeyMsg(KeyType::Down));
        [$tailing, $cmd] = $onApp->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($tailing->isTailing(), 'Enter enters the loading state');
        self::assertTrue($tailing->viewerFocused(), 'focus moves to the viewport');
        self::assertStringContainsString('Loading…', $tailing->view());

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLogTailLoadedMsg::class, $msg);

        [$loaded] = $tailing->update($msg);
        self::assertFalse($loaded->isTailing());
        self::assertNotNull($loaded->tail());
        $view = $loaded->view();
        self::assertStringContainsString('log line 1', $view);
        self::assertStringContainsString('truncated — older lines omitted', $view);
    }

    public function testEnterOnAllLogsCallsTailAll(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->fileListPayload())
            ->json(200, $this->allTailPayload());
        $screen = $this->withFiles($transport);

        // Index 0 is "All logs (merged)".
        [$tailing, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLogTailLoadedMsg::class, $msg);

        [$loaded] = $tailing->update($msg);
        self::assertNull($loaded->tail()?->file);
        self::assertStringContainsString('/api/v1/admin/logs/tail-all', $transport->requestAt(1)['url']);
        $view = $loaded->view();
        self::assertStringContainsString('started', $view);
    }

    public function testTailFetchFailureShowsTheErrorInTheViewport(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->fileListPayload())
            ->json(500, ['error' => 'read-fail']);
        $screen = $this->withFiles($transport);

        [$tailing, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLogFailedMsg::class, $msg);

        [$failed] = $tailing->update($msg);
        self::assertSame('Could not read the log.', $failed->error());
        $view = $failed->view();
        self::assertStringContainsString('Could not read the log', $view);
        self::assertStringContainsString('Press r to retry', $view);
    }

    public function testTabAndArrowsSwitchFocus(): void
    {
        $screen = $this->withFiles((new FakeTransport())->json(200, $this->fileListPayload()));
        self::assertFalse($screen->viewerFocused());

        [$tab] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertTrue($tab->viewerFocused());

        [$back] = $tab->update(new KeyMsg(KeyType::Tab));
        self::assertFalse($back->viewerFocused());

        [$right] = $screen->update(new KeyMsg(KeyType::Right));
        self::assertTrue($right->viewerFocused());
        // Right while already on the viewer is a no-op (same instance).
        [$stillRight] = $right->update(new KeyMsg(KeyType::Right));
        self::assertSame($right, $stillRight);

        [$left] = $right->update(new KeyMsg(KeyType::Left));
        self::assertFalse($left->viewerFocused());
    }

    public function testViewportScrollsWithArrowsAndPaging(): void
    {
        $screen = $this->loadedTail();
        // Focus the viewport.
        [$viewer] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertTrue($viewer->viewerFocused());
        self::assertSame(0, $viewer->scrollOffset());

        [$down] = $viewer->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->scrollOffset());

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->scrollOffset());
        // Up at the top is clamped (same instance).
        [$stillTop] = $up->update(new KeyMsg(KeyType::Up));
        self::assertSame($up, $stillTop);

        [$paged] = $viewer->update(new KeyMsg(KeyType::PageDown));
        self::assertGreaterThan(1, $paged->scrollOffset());

        [$end] = $viewer->update(new KeyMsg(KeyType::End));
        self::assertSame($end->scrollOffset(), $end->scrollOffset());
        self::assertGreaterThan(0, $end->scrollOffset(), 'End jumps to the last window');

        [$home] = $end->update(new KeyMsg(KeyType::Home));
        self::assertSame(0, $home->scrollOffset());
    }

    public function testRRefetchesTheActiveTail(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->fileListPayload())
            ->json(200, $this->tailPayload())
            ->json(200, $this->tailPayload());
        $screen = $this->withFiles($transport);
        [$tailing, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        [$loaded] = $tailing->update($this->runCmd($cmd) ?? new AdminLogFailedMsg('x'));

        [$reloading, $rcmd] = $loaded->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertTrue($reloading->isTailing());
        $msg = $this->runCmd($rcmd);
        self::assertInstanceOf(AdminLogTailLoadedMsg::class, $msg);
    }

    public function testRRefetchesTheFileListWhenNothingIsTailed(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->fileListPayload())
            ->json(200, $this->fileListPayload());
        $screen = $this->withFiles($transport);

        [$reloading, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertFalse($reloading->filesLoaded());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLogFilesLoadedMsg::class, $msg);
    }

    public function testAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminLogsScreen(new AdminClient($api), cols: 120, rows: 40);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testEscapeAndQGoBack(): void
    {
        $screen = $this->withFiles((new FakeTransport())->json(200, $this->fileListPayload()));

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testEmptyTailRendersAPlaceholder(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->fileListPayload())
            ->json(200, $this->envelope(['file' => 'app.log', 'lines' => [], 'truncated' => false]));
        $screen = $this->withFiles($transport);

        [$tailing, $cmd] = $screen->update(new KeyMsg(KeyType::Down));
        [$tailing2, $cmd2] = $tailing->update(new KeyMsg(KeyType::Enter));
        [$loaded] = $tailing2->update($this->runCmd($cmd2) ?? new AdminLogFailedMsg('x'));

        self::assertStringContainsString('(empty)', $loaded->view());
    }

    public function testEnterOnAFileWithAnEmptyNameFallsBackToTheFileList(): void
    {
        // A defensive guard: a file row with no name re-fetches the list rather
        // than tailing an empty path.
        $transport = (new FakeTransport())
            ->json(200, $this->envelope(['files' => [['name' => '', 'size' => 0, 'modified_at' => 'x']]]))
            ->json(200, $this->fileListPayload());
        $screen = $this->withFiles($transport);

        [$tailing, $cmd] = $screen->update(new KeyMsg(KeyType::Down)); // index 1 = the empty-name file
        [, $cmd] = $tailing->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLogFilesLoadedMsg::class, $msg);
    }

    public function testScrollingTheViewportWithNoTailIsANoOp(): void
    {
        $screen = $this->withFiles((new FakeTransport())->json(200, $this->fileListPayload()));
        [$viewer] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertTrue($viewer->viewerFocused());

        // No tail loaded yet → maxScroll is 0, so End/Down keep the offset at 0.
        [$end] = $viewer->update(new KeyMsg(KeyType::End));
        self::assertSame(0, $end->scrollOffset());
        [$down] = $viewer->update(new KeyMsg(KeyType::Down));
        self::assertSame($viewer, $down, 'a clamped scroll returns the same instance');
    }

    public function testAnUnhandledKeyOnTheListIsANoOp(): void
    {
        $screen = $this->withFiles((new FakeTransport())->json(200, $this->fileListPayload()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testAnUnhandledKeyOnTheViewerIsANoOp(): void
    {
        $screen = $this->loadedTail();
        [$viewer] = $screen->update(new KeyMsg(KeyType::Tab));

        [$next, $cmd] = $viewer->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertSame($viewer, $next);
        self::assertNull($cmd);
    }

    public function testResizeReflowsTheScreen(): void
    {
        $screen = $this->withFiles((new FakeTransport())->json(200, $this->fileListPayload()));

        [$resized, $cmd] = $screen->update(new WindowSizeMsg(80, 24));

        self::assertNull($cmd);
        self::assertStringContainsString('All logs', $resized->view());
    }

    public function testCrumbLabelAndImmutability(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->fileListPayload()));
        self::assertSame('Logs', $screen->crumbLabel());

        $crumbed = $screen->withCrumbs(['Admin', 'Logs']);
        self::assertNotSame($screen, $crumbed);

        $themed = $screen->withTheme(Theme::midnight());
        self::assertNotSame($screen, $themed);
    }

    public function testAnUnhandledMessageIsANoOp(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->fileListPayload()));

        [$next, $cmd] = $screen->update(new class implements Msg {});

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- helpers -------------------------------------------------------

    /** A screen with the file list loaded and app.log tailed (60 lines, truncated). */
    private function loadedTail(): AdminLogsScreen
    {
        $transport = (new FakeTransport())
            ->json(200, $this->fileListPayload())
            ->json(200, $this->tailPayload());
        $screen = $this->withFiles($transport);
        [$onApp] = $screen->update(new KeyMsg(KeyType::Down));
        [$tailing, $cmd] = $onApp->update(new KeyMsg(KeyType::Enter));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLogTailLoadedMsg::class, $msg);

        // Re-focus the list so callers start from a known focus (the tail sets
        // focus to the viewer; toggle back).
        [$loaded] = $tailing->update($msg);
        [$listFocused] = $loaded->update(new KeyMsg(KeyType::Left));

        return $listFocused;
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

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null];
        $promise->then(function ($value) use (&$state): void {
            $state['value'] = $value;
            $state['done'] = true;
            Loop::stop();
        });

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        return $state['value'];
    }
}

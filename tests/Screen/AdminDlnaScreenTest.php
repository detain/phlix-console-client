<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\DlnaServerStatus;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminDlnaActionDoneMsg;
use Phlix\Console\Msg\AdminDlnaActionFailedMsg;
use Phlix\Console\Msg\AdminDlnaFailedMsg;
use Phlix\Console\Msg\AdminDlnaStatusLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AdminDlnaScreen;
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
use SugarCraft\Toast\ToastType;

final class AdminDlnaScreenTest extends TestCase
{
    /** A configured + running DLNA status payload (TOP-LEVEL, unenveloped). */
    private function runningPayload(): array
    {
        return [
            'enabled' => true,
            'running' => true,
            'serverId' => 'srv-1',
            'friendlyName' => 'Phlix Living Room',
            'port' => 1900,
            'baseUrl' => 'http://10.0.0.5:1900/',
        ];
    }

    /** A configured + stopped DLNA status payload. */
    private function stoppedPayload(): array
    {
        return [
            'enabled' => true,
            'running' => false,
            'serverId' => 'srv-1',
            'friendlyName' => 'Phlix Living Room',
            'port' => 1900,
            'baseUrl' => 'http://10.0.0.5:1900/',
        ];
    }

    /** A not-configured DLNA status payload (with its message). */
    private function notConfiguredPayload(): array
    {
        return [
            'enabled' => false,
            'running' => false,
            'serverId' => null,
            'friendlyName' => null,
            'port' => null,
            'baseUrl' => null,
            'message' => 'DLNA server not configured',
        ];
    }

    private function screenWith(FakeTransport $transport): AdminDlnaScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminDlnaScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** A screen whose token has NO refresh token, so a 401 is not retried. */
    private function screenWithNoRefresh(FakeTransport $transport): AdminDlnaScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));

        return new AdminDlnaScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** Drive init → the loaded Msg, then apply it. */
    private function loaded(FakeTransport $transport): AdminDlnaScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminDlnaStatusLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    // ---- init / loading / error ----------------------------------------

    public function testInitFetchesTheStatus(): void
    {
        $transport = (new FakeTransport())->json(200, $this->runningPayload());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminDlnaStatusLoadedMsg::class, $msg);
        self::assertTrue($msg->status->running);
    }

    public function testLoadingStateBeforeStatus(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->runningPayload()));

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading DLNA server status', $screen->view());
    }

    public function testFetchFailureShowsAnErrorAndRetryHint(): void
    {
        $transport = (new FakeTransport())->json(500, ['error' => 'boom']);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminDlnaFailedMsg::class, $msg);

        [$next] = $screen->update($msg);
        self::assertFalse($next->isLoaded());
        self::assertNotNull($next->error());
        self::assertStringContainsString('Could not load', $next->view());
        self::assertStringContainsString('Press r to retry', $next->view());
    }

    // ---- status panel render -------------------------------------------

    public function testRendersTheRunningStatusPanel(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->runningPayload()));

        self::assertTrue($screen->isLoaded());
        $view = $screen->view();
        self::assertStringContainsString('Running', $view);
        self::assertStringContainsString('srv-1', $view);
        self::assertStringContainsString('Phlix Living Room', $view);
        self::assertStringContainsString('1900', $view);
        self::assertStringContainsString('http://10.0.0.5:1900/', $view);
        self::assertStringContainsString('Press t to stop', $view);
        // The hint offers stop when running.
        self::assertStringContainsString('t stop', $view);
    }

    public function testRendersTheStoppedStatusPanel(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->stoppedPayload()));

        $view = $screen->view();
        self::assertStringContainsString('Stopped', $view);
        self::assertStringContainsString('Press s to start', $view);
        self::assertStringContainsString('s start', $view);
    }

    public function testRendersTheNotConfiguredStatusPanelWithItsMessage(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->notConfiguredPayload()));

        $view = $screen->view();
        self::assertStringContainsString('Not configured', $view);
        self::assertStringContainsString('DLNA server not configured', $view);
        // Not running → the start control is offered.
        self::assertStringContainsString('Press s to start', $view);
        // Optional fields render an em-dash placeholder.
        self::assertStringContainsString('—', $view);
    }

    // ---- start / stop --------------------------------------------------

    public function testStartWhenStoppedPostsThenToastsAndRefetchesNowRunning(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->stoppedPayload())   // init
            ->json(200, ['success' => true])       // start
            ->json(200, $this->runningPayload());  // refetch
        $screen = $this->loaded($transport);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        self::assertTrue($busy->isBusy());

        // The action cmd resolves to a Done msg; applying it produces the
        // toast + refetch batch.
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminDlnaActionDoneMsg::class, $done);
        self::assertSame('DLNA server started', $done->message);

        // The start POST hit /start.
        self::assertStringContainsString('/api/v1/admin/dlna/start', $transport->requestAt(1)['url']);

        [$working, $batch] = $busy->update($done);
        self::assertTrue($working->isBusy());
        $msgs = $this->collectCmd($batch);

        $toast = $this->firstOf($msgs, ShowToastMsg::class);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Success, $toast->type);
        self::assertStringContainsString('DLNA server started', $toast->message);

        $loaded = $this->firstOf($msgs, AdminDlnaStatusLoadedMsg::class);
        self::assertInstanceOf(AdminDlnaStatusLoadedMsg::class, $loaded);

        [$after] = $working->update($loaded);
        self::assertNotNull($after->status());
        self::assertTrue($after->status()->running, 'the refetched status is now running');
    }

    public function testStopWhenRunningPostsThenToastsAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->runningPayload())   // init
            ->json(200, ['success' => true])       // stop
            ->json(200, $this->stoppedPayload());  // refetch
        $screen = $this->loaded($transport);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 't'));
        self::assertTrue($busy->isBusy());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminDlnaActionDoneMsg::class, $done);
        self::assertSame('DLNA server stopped', $done->message);

        self::assertStringContainsString('/api/v1/admin/dlna/stop', $transport->requestAt(1)['url']);

        [$working, $batch] = $busy->update($done);
        $msgs = $this->collectCmd($batch);

        $toast = $this->firstOf($msgs, ShowToastMsg::class);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertStringContainsString('DLNA server stopped', $toast->message);

        $loaded = $this->firstOf($msgs, AdminDlnaStatusLoadedMsg::class);
        self::assertInstanceOf(AdminDlnaStatusLoadedMsg::class, $loaded);
        [$after] = $working->update($loaded);
        self::assertFalse($after->status()->running);
    }

    public function testStartIsIgnoredWhenAlreadyRunning(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->runningPayload()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        self::assertSame($screen, $next, 's is a no-op when the server is already running');
        self::assertNull($cmd);
    }

    public function testStopIsIgnoredWhenNotRunning(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->stoppedPayload()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 't'));

        self::assertSame($screen, $next, 't is a no-op when the server is not running');
        self::assertNull($cmd);
    }

    public function testActionFailureToastsTheFriendlyMessageAndLeavesTheStatusUnchanged(): void
    {
        // LANDMINE: the 409 failure body uses `message`, NOT `error` — the client
        // re-surfaces it, so the toast shows the friendly text, not "HTTP 409".
        $transport = (new FakeTransport())
            ->json(200, $this->stoppedPayload())                                       // init
            ->json(409, ['success' => false, 'message' => 'DLNA server is already running']); // start
        $screen = $this->loaded($transport);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        $msg = $this->runCmd($cmd);

        self::assertInstanceOf(AdminDlnaActionFailedMsg::class, $msg);
        self::assertSame('DLNA server is already running', $msg->message);

        [$after, $toastCmd] = $busy->update($msg);
        self::assertFalse($after->isBusy());
        // The status is unchanged (still the stopped one).
        self::assertNotNull($after->status());
        self::assertFalse($after->status()->running);

        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('DLNA server is already running', $toast->message);
        self::assertStringNotContainsString('HTTP 409', $toast->message);
    }

    public function testActionAuthErrorSurfacesASessionExpiry(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->stoppedPayload())    // init
            ->json(401, ['error' => 'Unauthorized']); // start (no refresh → not retried)
        $screen = $this->screenWithNoRefresh($transport);
        $loaded = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminDlnaStatusLoadedMsg::class, $loaded);
        $screen = $screen->update($loaded)[0];

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        $msg = $this->runCmd($cmd);

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testFetchAuthErrorSurfacesASessionExpiry(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $screen = $this->screenWithNoRefresh($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- refresh / nav / misc ------------------------------------------

    public function testRRefetchesTheStatus(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->stoppedPayload())   // init
            ->json(200, $this->runningPayload());  // refresh
        $screen = $this->loaded($transport);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertTrue($busy->isBusy());
        self::assertFalse($busy->isLoaded());

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminDlnaStatusLoadedMsg::class, $msg);
        self::assertTrue($msg->status->running);
    }

    public function testActionsAreIgnoredWhileBusy(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->stoppedPayload()));

        // First `s` arms the busy state.
        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        self::assertTrue($busy->isBusy());

        // A second `s` while busy is a no-op (no new command).
        [$same, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 's'));
        self::assertSame($busy, $same);
        self::assertNull($cmd);
    }

    public function testActionKeysAreNoOpsBeforeTheStatusLoads(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->stoppedPayload()));

        // Before the status arrives `s`/`t` do nothing.
        [$sNext, $sCmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        self::assertSame($screen, $sNext);
        self::assertNull($sCmd);

        [$tNext, $tCmd] = $screen->update(new KeyMsg(KeyType::Char, 't'));
        self::assertSame($screen, $tNext);
        self::assertNull($tCmd);
    }

    public function testTheBusyStateRendersAWorkingNote(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->stoppedPayload())  // init
            ->json(200, ['success' => true]);     // start (pending)
        $screen = $this->loaded($transport);

        // Firing `s` enters the busy state without yet applying the result.
        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        self::assertTrue($busy->isBusy());
        self::assertStringContainsString('Working…', $busy->view());
    }

    public function testEscapeAndQGoBack(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->runningPayload()));

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testAnUnhandledKeyIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->runningPayload()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testANonKeyArrowIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->runningPayload()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Up));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testAnUnhandledMsgIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->runningPayload()));

        [$next, $cmd] = $screen->update(new class implements Msg {});

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testResizeAdjustsTheFrame(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->runningPayload()));

        [$resized] = $screen->update(new WindowSizeMsg(60, 20));

        // The screen still renders; resize is reflected in the frame width.
        self::assertNotSame($screen, $resized);
        self::assertStringContainsString('Running', $resized->view());
    }

    public function testHintBeforeLoadOffersRefreshOnly(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->runningPayload()));

        $view = $screen->view();
        self::assertStringContainsString('r refresh', $view);
        self::assertStringNotContainsString('s start', $view);
        self::assertStringNotContainsString('t stop', $view);
    }

    public function testCrumbLabelAndWithCrumbsAreImmutable(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->runningPayload()));

        self::assertSame('DLNA', $screen->crumbLabel());

        $withCrumbs = $screen->withCrumbs(['Admin', 'DLNA']);
        self::assertNotSame($screen, $withCrumbs);
        self::assertSame('DLNA', $withCrumbs->crumbLabel());
    }

    public function testThemeIsAppliedImmutably(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->runningPayload()));

        $themed = $screen->withTheme(Theme::midnight());
        self::assertNotSame($screen, $themed);
        self::assertStringContainsString('Running', $themed->view());
    }

    // ---- harness -------------------------------------------------------

    /**
     * Return the first Msg of the given class from a collected list, or null.
     *
     * @param list<Msg>     $msgs
     * @param class-string  $class
     */
    private function firstOf(array $msgs, string $class): ?Msg
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof $class) {
                return $msg;
            }
        }

        return null;
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

    /**
     * Run a command and collect EVERY resolved Msg (flattening a batch and
     * awaiting its async legs).
     *
     * @return list<Msg>
     */
    private function collectCmd(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }
        $result = $cmd();
        if ($result instanceof BatchMsg) {
            $out = [];
            foreach ($result->cmds as $child) {
                foreach ($this->collectCmd($child) as $msg) {
                    $out[] = $msg;
                }
            }

            return $out;
        }
        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? [$msg] : [];
        }

        return $result instanceof Msg ? [$result] : [];
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

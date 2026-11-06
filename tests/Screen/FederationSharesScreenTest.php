<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\FederationShareActionDoneMsg;
use Phlix\Console\Msg\FederationSharesFailedMsg;
use Phlix\Console\Msg\FederationSharesOutgoingLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\FederationSharesScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Tests\Support\RunCountingLoopSpy;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

/**
 * CU-006 [review console__uxdesign C2, console__docmaint m4] — the cure of the
 * blank, key-dead Federation Shares modal. The first cut shipped body()
 * returning '' (an empty Chrome frame), update() returning [$this, null] for
 * EVERY message (not even Escape/q could leave it — the only exit was knowing
 * the palette carried Home), and hardcoded 80x24 geometry that ignored
 * WindowSizeMsg. It now rides the canonical hub-screen shape of its two
 * siblings (SharedWithMeScreen, InviteLinksScreen): AsyncCmd fetch on the
 * outer loop, loading/error/empty/list frames, Escape routed by KEY TYPE, and
 * `r` revoking the selected outgoing share against the hub's DELETE verb.
 *
 * Every fixture here is the hub's ACTUAL wire shape, re-derived from
 * FederationController::getOutgoingShares (top-level `outgoing_shares` rows
 * keyed by `id`) — the body-blind era where a stale `$body['outgoing']` read
 * parsed the list forever-empty is exactly what these pins kill.
 */
final class FederationSharesScreenTest extends TestCase
{
    /** @return list<array<string, mixed>> outgoing-share rows (hub getOutgoingShares shape) */
    private function outgoingPayload(): array
    {
        return [
            [
                'id' => 'os-1',
                'library_id' => 'lib-1',
                'library_name' => 'Movies',
                'peer_id' => 'hub-alpha',
                'permission' => 'read',
                'status' => 'active',
                'shared_at' => '2026-10-01T00:00:00Z',
                'revoked_at' => null,
            ],
            [
                'id' => 'os-2',
                'library_id' => 'lib-2',
                'library_name' => 'Music',
                'peer_id' => 'hub-beta',
                'permission' => 'readwrite',
                'status' => 'pending',
                'shared_at' => '2026-10-05T00:00:00Z',
                'revoked_at' => null,
            ],
        ];
    }

    /** @param list<array<string, mixed>> $shares */
    private function outgoingEnvelope(array $shares): array
    {
        return ['outgoing_shares' => $shares];
    }

    private function screenWith(FakeTransport $transport): FederationSharesScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new FederationSharesScreen(new HubClient($api));
    }

    /** Drive init → the loaded Msg, then apply it. */
    private function loaded(FakeTransport $transport): FederationSharesScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(FederationSharesOutgoingLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    // ---- init / loading --------------------------------------------------

    public function testInitReturnsAnAsyncCmdRidingTheOuterLoop(): void
    {
        $transport = (new FakeTransport())->json(200, $this->outgoingEnvelope($this->outgoingPayload()));
        $screen = $this->screenWith($transport);

        $cmd = $screen->init();
        self::assertNotNull($cmd, 'the screen must fetch on open, not render a dead frame');

        $outcome = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $outcome, 'init must yield an AsyncCmd, not a drained value');

        $msg = $this->await($outcome->promise);
        self::assertInstanceOf(FederationSharesOutgoingLoadedMsg::class, $msg);
        self::assertSame($this->outgoingPayload(), $msg->shares);
        self::assertSame('GET', $transport->lastRequest()['method']);
        self::assertStringContainsString('/api/v1/me/federation/library-shares/outgoing', $transport->lastRequest()['url']);
    }

    public function testFetchFailureResolvesAFailedMsg(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('boom'));
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(FederationSharesFailedMsg::class, $msg);
        self::assertStringContainsString('Could not reach the server', $msg->message);
        self::assertStringNotContainsString('boom', $msg->message);
    }

    public function testUpdateRoutesPayloadMsgsOntoClones(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->outgoingEnvelope([])));

        [$next, $cmd] = $screen->update(new FederationSharesOutgoingLoadedMsg($this->outgoingPayload()));
        self::assertNotSame($screen, $next, 'the screen is immutable: a payload lands on a clone');
        self::assertNull($cmd);
        self::assertFalse($next->isLoading());
        self::assertNull($next->error());
        self::assertSame($this->outgoingPayload(), $next->items());

        [$afterFailed, $failedCmd] = $next->update(new FederationSharesFailedMsg('nope'));
        self::assertNotSame($next, $afterFailed);
        self::assertNull($failedCmd);
        self::assertSame('nope', $afterFailed->error());
        self::assertFalse($afterFailed->isLoading());
    }

    // ---- frame rendering (CU-006: the blank-view cure) --------------------

    public function testViewRendersLoadingThenListWithCursorAndNeverABlankFrame(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->outgoingEnvelope([])));
        self::assertStringContainsString('Loading outgoing shares', $screen->view(), 'a fresh screen shows its spinner state, not a blank frame');

        [$loaded] = $screen->update(new FederationSharesOutgoingLoadedMsg($this->outgoingPayload()));
        $view = $loaded->view();
        self::assertStringContainsString('Federation Shares', $view);
        self::assertStringContainsString('Movies', $view, 'share rows render their library names');
        self::assertStringContainsString('Music', $view);
        self::assertStringContainsString('hub-alpha', $view, 'the peer the library was shared with is visible');
        self::assertStringContainsString('▶', $view, 'the cursor renders on the selected row');
    }

    public function testViewDisclosesTheFailureSentenceAndTheEmptyState(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->outgoingEnvelope([])));

        [$failed] = $screen->update(new FederationSharesFailedMsg('Could not reach the server.'));
        self::assertStringContainsString('Could not reach the server', $failed->view(), 'the swallow-the-error bug is dead: failures render');

        [$empty] = $screen->update(new FederationSharesOutgoingLoadedMsg([]));
        self::assertStringContainsString('not shared any library', $empty->view(), 'the empty state says so instead of vanishing');
    }

    public function testWindowSizeResizesTheFrame(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->outgoingEnvelope([])));

        [$next, $cmd] = $screen->update(new WindowSizeMsg(120, 40));

        self::assertNotSame($screen, $next);
        self::assertNull($cmd);
        self::assertStringContainsString('Loading outgoing shares', $next->view());
    }

    // ---- keyboard (CU-006: the key-dead-modal cure) -----------------------

    public function testEscapeIsRoutedByKeyTypeNotRune(): void
    {
        // [review console__docmaint m4] the vendored KeyMsg carries rune '' for
        // Escape; only the TYPE arm can close the frame. The swallow-all
        // update() ignored BOTH — that is the dead-end this pin kills.
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->outgoingEnvelope([])));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertNotNull($cmd, 'Escape must close the screen — no more modal dead end');
        self::assertInstanceOf(NavigateBackMsg::class, $cmd());

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $qCmd());

        [, $qShiftCmd] = $screen->update(new KeyMsg(KeyType::Char, 'Q'));
        self::assertInstanceOf(NavigateBackMsg::class, $qShiftCmd());
    }

    public function testArrowsMoveTheCursorAndRowsFollow(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->outgoingEnvelope([])));
        [$loaded] = $screen->update(new FederationSharesOutgoingLoadedMsg($this->outgoingPayload()));

        [$down] = $loaded->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex(), 'Down moves the cursor');
        self::assertStringContainsString('▶ Music', $down->view());

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex(), 'Up moves it back');

        [$viaJ] = $loaded->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertSame(1, $viaJ->selectedIndex(), 'vim j mirrors Down');
        [$viaK] = $viaJ->update(new KeyMsg(KeyType::Char, 'k'));
        self::assertSame(0, $viaK->selectedIndex(), 'vim k mirrors Up');
    }

    // ---- revoke -----------------------------------------------------------

    public function testRevokeKeySendsTheHubDeleteAndResolvesTheDoneMsg(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->outgoingEnvelope($this->outgoingPayload()))
            // Hub truth (revokeOutgoingShare): success is 204 with NO body.
            ->raw(204, '');
        $screen = $this->loaded($transport);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertNotNull($cmd);
        $outcome = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $outcome);

        $msg = $this->await($outcome->promise);
        self::assertInstanceOf(FederationShareActionDoneMsg::class, $msg);
        self::assertSame('revoked', $msg->message);
        self::assertSame('DELETE', $transport->lastRequest()['method']);
        self::assertStringContainsString('/library-shares/outgoing/os-1', $transport->lastRequest()['url']);
        self::assertSame('', $transport->lastRequest()['body'], 'revoke carries no request body');
    }

    public function testDoneMsgToastsAndReReadsTheServerList(): void
    {
        $transport = (new FakeTransport())->json(200, $this->outgoingEnvelope([]));
        $screen = $this->screenWith($transport);

        [$after, $cmd] = $screen->update(new FederationShareActionDoneMsg('revoked'));
        self::assertNotSame($screen, $after);
        self::assertTrue($after->isLoading(), 'the re-read arms the spinner');
        self::assertNotNull($cmd);

        $batch = $cmd();
        self::assertInstanceOf(BatchMsg::class, $batch);
        self::assertCount(2, $batch->cmds, 'toast + refetch ride one batch');
        self::assertInstanceOf(ShowToastMsg::class, ($batch->cmds[0])());
        self::assertStringContainsString('revoked', strtolower((string) (($batch->cmds[0])())->message));
    }

    public function testRevokeKeyWithoutLoadedSharesIsANoop(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->outgoingEnvelope([])));

        [, $revokeCmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertNull($revokeCmd);
    }

    // ---- nested-drain tripwire (the M36 regression probe) ------------------

    public function testInitCommandNeverReEntersTheLoopRun(): void
    {
        $transport = (new FakeTransport())->json(200, $this->outgoingEnvelope($this->outgoingPayload()));
        $screen = $this->screenWith($transport);
        $cmd = $screen->init();

        self::assertNotNull($cmd);
        $runCount = $this->probeRunCount($cmd);

        self::assertSame(
            1,
            $runCount,
            'a cmd closure re-entered Loop::run() to drain its promise — nested drains hijack '
            . "(and their stop() kills) the app's single event pump; ride the outer loop via Cmd::promise",
        );
    }

    /**
     * Installs a counting spy over the global loop, invokes `$cmd` from a
     * future tick while the outer pump runs, and returns how many times
     * run() was entered before everything settled.
     */
    private function probeRunCount(\Closure $cmd): int
    {
        $spy = new RunCountingLoopSpy();
        Loop::set($spy);

        try {
            $spy->futureTick(static function () use ($cmd): void {
                $cmd();
            });
            $spy->addTimer(0.05, static function () use ($spy): void {
                $spy->stop();
            });
            $spy->run();
        } finally {
            Loop::set(new StreamSelectLoop());
        }

        return $spy->runCount;
    }

    // ---- harness (mirrors SharedWithMeScreenTest) --------------------------

    private function runCmd(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }
        $result = $cmd();
        self::assertIsNotArray($result, 'a cmd closure returned a drained array — nested event-loop drain');

        return $result instanceof Msg ? $this->awaitMsg($result) : null;
    }

    private function awaitMsg(mixed $result): ?Msg
    {
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

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SharedWithMeActionDoneMsg;
use Phlix\Console\Msg\SharedWithMeFailedMsg;
use Phlix\Console\Msg\SharedWithMeLoadedMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\SharedWithMeScreen;
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
 * M-CONSOLE-36 contract for SharedWithMeScreen: hub work rides the OUTER
 * event loop as AsyncCmd messages. The screen must never re-enter
 * `Loop::run()`/`Loop::stop()` itself — Program::scheduleCmd() invokes these
 * closures inside the already-running global loop, and the shared
 * StreamSelectLoop has no re-entry guard (the nested drain's stop() kills
 * the app pump).
 *
 * CU-005 [review console__uxdesign C1] added the frame/render laws the first
 * cut never had (it shipped view() returning '') and moved every fixture onto
 * the hub's ACTUAL wire shapes: the list is the federation incoming-offers
 * resource the accept/reject verbs answer (`{incoming_offers: [...]}` rows
 * keyed by `id`), not the fabricated `{shares: [...]}` envelope the old parse
 * read forever-empty.
 */
final class SharedWithMeScreenTest extends TestCase
{
    /** @return list<array<string, mixed>> federation incoming-offer rows (hub FederationController::getIncomingOffers shape) */
    private function sharesPayload(): array
    {
        return [
            ['id' => 'share-1', 'peer_id' => 'hub-alpha', 'library_name' => 'Movies', 'permission' => 'read', 'status' => 'pending'],
            ['id' => 'share-2', 'peer_id' => 'hub-beta', 'library_name' => 'Music', 'permission' => 'read', 'status' => 'pending'],
        ];
    }

    /** @param list<array<string, mixed>> $offers */
    private function offersEnvelope(array $offers): array
    {
        return ['incoming_offers' => $offers];
    }

    private function screenWith(FakeTransport $transport): SharedWithMeScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new SharedWithMeScreen(new HubClient($api));
    }

    /** Drive init → the loaded Msg, then apply it. */
    private function loaded(FakeTransport $transport): SharedWithMeScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(SharedWithMeLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    // ---- init / loading --------------------------------------------------

    public function testInitReturnsAnAsyncCmdRidingTheOuterLoop(): void
    {
        $transport = (new FakeTransport())->json(200, $this->offersEnvelope($this->sharesPayload()));
        $screen = $this->screenWith($transport);

        $outcome = ($screen->init())();

        self::assertInstanceOf(AsyncCmd::class, $outcome, 'init must yield an AsyncCmd, not a drained value');

        $msg = $this->await($outcome->promise);
        self::assertInstanceOf(SharedWithMeLoadedMsg::class, $msg);
        self::assertSame($this->sharesPayload(), $msg->shares);
        self::assertSame('GET', $transport->lastRequest()['method']);
        // The list rides the SAME resource family as the accept/reject verbs
        // [review console__uxdesign C1] — not the fabricated /me/shares rail.
        self::assertStringContainsString('/api/v1/me/federation/library-shares/incoming', $transport->lastRequest()['url']);
    }

    public function testUpdateRoutesPayloadMsgsBackIntoTheModel(): void
    {
        // CU-005 clone-mutate law [review console__srphp M1]: payloads land on
        // a NEW model; the old in-place mutation (assertSame) is the bug.
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->offersEnvelope([])));

        [$next, $cmd] = $screen->update(new SharedWithMeLoadedMsg($this->sharesPayload()));
        self::assertNotSame($screen, $next, 'the screen is immutable: a payload lands on a clone');
        self::assertNull($cmd);
        self::assertFalse($next->isLoading());
        self::assertNull($next->error());
        self::assertSame($this->sharesPayload(), $next->items());

        [$afterFailed, $failedCmd] = $next->update(new SharedWithMeFailedMsg('nope'));
        self::assertNotSame($next, $afterFailed);
        self::assertNull($failedCmd);
        self::assertSame('nope', $afterFailed->error());
        self::assertFalse($afterFailed->isLoading());
    }

    public function testFetchFailureResolvesAFailedMsg(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('boom'));
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SharedWithMeFailedMsg::class, $msg);
        // M11 cure flip: raw transport tail replaced by the curated sentence.
        self::assertStringContainsString('Could not reach the server', $msg->message);
        self::assertStringNotContainsString('boom', $msg->message);
    }

    // ---- frame rendering (CU-005: the blank-view cure) ---------------------

    public function testViewRendersLoadingThenListWithCursorAndNeverABlankFrame(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->offersEnvelope([])));
        self::assertStringContainsString('Loading shared libraries', $screen->view(), 'a fresh screen shows its spinner state, not a blank frame');

        [$loaded] = $screen->update(new SharedWithMeLoadedMsg($this->sharesPayload()));
        $view = $loaded->view();
        self::assertStringContainsString('Shared With Me', $view);
        self::assertStringContainsString('Movies', $view, 'offer rows render their library names');
        self::assertStringContainsString('Music', $view);
        self::assertStringContainsString('▶', $view, 'the cursor renders on the selected row');
    }

    public function testViewDisclosesTheFailureSentenceAndTheEmptyState(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->offersEnvelope([])));

        [$failed] = $screen->update(new SharedWithMeFailedMsg('Could not reach the server.'));
        self::assertStringContainsString('Could not reach the server', $failed->view(), 'the swallow-the-error bug is dead: failures render');

        [$empty] = $screen->update(new SharedWithMeLoadedMsg([]));
        self::assertStringContainsString('Nothing is waiting for you here', $empty->view(), 'the empty state says so instead of vanishing');
    }

    public function testWindowSizeResizesTheFrame(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->offersEnvelope([])));

        [$next, $cmd] = $screen->update(new WindowSizeMsg(120, 40));

        self::assertNotSame($screen, $next);
        self::assertNull($cmd);
        self::assertStringContainsString('Loading shared libraries', $next->view());
    }

    // ---- keyboard ----------------------------------------------------------

    public function testEscapeIsRoutedByKeyTypeNotRune(): void
    {
        // [review console__docmaint m4] the vendored KeyMsg carries rune '' for
        // Escape; the old rune-match arm was dead code. Escape must navigate
        // back off the frame on the TYPE alone.
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->offersEnvelope([])));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertNotNull($cmd);
        self::assertInstanceOf(NavigateBackMsg::class, $cmd());

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $qCmd());
    }

    public function testArrowsMoveTheCursorAndRowsFollow(): void
    {
        // [review console__uxdesign C1] selectedIndex never moved at all in the
        // first cut — every accept answered row 0 no matter where you looked.
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->offersEnvelope([])));
        [$loaded] = $screen->update(new SharedWithMeLoadedMsg($this->sharesPayload()));

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

    // ---- accept / reject ---------------------------------------------------

    public function testAcceptKeyReturnsAnAsyncCmdResolvingTheDoneMsg(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->offersEnvelope($this->sharesPayload()))
            ->json(200, ['message' => 'ok']);
        $screen = $this->loaded($transport);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertNotNull($cmd);
        $outcome = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $outcome);

        $msg = $this->await($outcome->promise);
        self::assertInstanceOf(SharedWithMeActionDoneMsg::class, $msg);
        self::assertSame('accepted', $msg->message);
        self::assertSame('POST', $transport->lastRequest()['method']);
        self::assertStringContainsString('/library-shares/incoming/share-1/accept', $transport->lastRequest()['url']);
    }

    public function testDoneMsgToastsAndReReadsTheServerList(): void
    {
        // CU-005: the action result must become VISIBLE (toast) and the list
        // must be re-read from the hub — the server owns what stays pending.
        $transport = (new FakeTransport())->json(200, $this->offersEnvelope([]));
        $screen = $this->screenWith($transport);

        [$after, $cmd] = $screen->update(new SharedWithMeActionDoneMsg('accepted'));
        self::assertNotSame($screen, $after);
        self::assertTrue($after->isLoading(), 'the re-read arms the spinner');
        self::assertNotNull($cmd);

        $batch = $cmd();
        self::assertInstanceOf(BatchMsg::class, $batch);
        self::assertCount(2, $batch->cmds, 'toast + refetch ride one batch');
        self::assertInstanceOf(ShowToastMsg::class, ($batch->cmds[0])());
        self::assertStringContainsString('accepted', strtolower((string) (($batch->cmds[0])())->message));
    }

    public function testRejectKeyResolvesTheDoneMsgAndFailureMapsToFailedMsg(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->offersEnvelope($this->sharesPayload()))
            ->json(200, ['message' => 'ok'])
            ->json(200, $this->offersEnvelope($this->sharesPayload()))
            ->fail(new \RuntimeException('denied'));
        $screen = $this->loaded($transport);

        [, $acceptCmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        $accepted = $this->runCmd($acceptCmd);
        self::assertInstanceOf(SharedWithMeActionDoneMsg::class, $accepted);
        self::assertSame('rejected', $accepted->message);

        $retryScreen = $this->loaded($transport);
        [, $failCmd] = $retryScreen->update(new KeyMsg(KeyType::Char, 'r'));
        $failed = $this->runCmd($failCmd);
        self::assertInstanceOf(SharedWithMeFailedMsg::class, $failed);
        // M11 cure flip: raw reject-failure tail no longer crosses into display.
        self::assertStringContainsString('Could not reach the server', $failed->message);
        self::assertStringNotContainsString('denied', $failed->message);
    }

    public function testActionKeysWithoutLoadedSharesAreNoops(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->offersEnvelope([])));

        [, $acceptCmd] = $screen->update(new KeyMsg(KeyType::Char, 'a'));
        [, $rejectCmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertNull($acceptCmd);
        self::assertNull($rejectCmd);
    }

    // ---- nested-drain tripwire (the M36 regression probe) ------------------

    /**
     * Run the init command the way Program does — from a tick WHILE the loop
     * is pumping — and count how many times `run()` was entered. A nested
     * `Loop::get()->run()` drain re-enters the shared loop (runCount 2+);
     * a loop-agnostic AsyncCmd closure never enters it (runCount 1).
     */
    public function testInitCommandNeverReEntersTheLoopRun(): void
    {
        $transport = (new FakeTransport())->json(200, $this->offersEnvelope($this->sharesPayload()));
        $screen = $this->screenWith($transport);
        $cmd = $screen->init();

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

    // ---- harness (mirrors AdminDlnaScreenTest) -----------------------------

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

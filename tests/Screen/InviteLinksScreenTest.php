<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\InviteLinkCreatedMsg;
use Phlix\Console\Msg\InviteLinkRevokedMsg;
use Phlix\Console\Msg\InviteLinksFailedMsg;
use Phlix\Console\Msg\InviteLinksLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\InviteLinksScreen;
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
 * M-CONSOLE-36 contract for InviteLinksScreen: hub work rides the OUTER
 * event loop as AsyncCmd messages. The screen must never re-enter
 * `Loop::run()`/`Loop::stop()` itself — Program::scheduleCmd() invokes these
 * closures inside the already-running global loop, and the shared
 * StreamSelectLoop has no re-entry guard (the nested drain's stop() kills
 * the app pump).
 *
 * CU-005 [review console__uxdesign C1] added the frame/render laws the first
 * cut never had (it shipped view() returning '') and moved every fixture onto
 * the hub's ACTUAL wire shapes: the list envelope is `{invite_links: [...]}`
 * (the old `inviteLinks` key was fabricated and parsed empty forever), rows
 * are `{id, url, permission, max_uses, use_count, ...}` — there is no `code`
 * key on the wire — and create resolves the server's flat 201 row instead of
 * the screen inventing an empty placeholder.
 */
final class InviteLinksScreenTest extends TestCase
{
    /** @return list<array<string, mixed>> invite-link rows (hub InviteLink::toPayload shape) */
    private function linksPayload(): array
    {
        return [
            ['id' => 'inv-1', 'url' => 'https://hub/accept-invite/ABCD', 'permission' => 'read', 'max_uses' => 5, 'use_count' => 0],
        ];
    }

    /** @param list<array<string, mixed>> $links */
    private function linksEnvelope(array $links): array
    {
        return ['invite_links' => $links];
    }

    private function screenWith(FakeTransport $transport): InviteLinksScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new InviteLinksScreen(new HubClient($api));
    }

    /** Drive init → the loaded Msg, then apply it. */
    private function loaded(FakeTransport $transport): InviteLinksScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(InviteLinksLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    // ---- init / loading --------------------------------------------------

    public function testInitReturnsAnAsyncCmdRidingTheOuterLoop(): void
    {
        $transport = (new FakeTransport())->json(200, $this->linksEnvelope($this->linksPayload()));
        $screen = $this->screenWith($transport);

        $outcome = ($screen->init())();

        self::assertInstanceOf(AsyncCmd::class, $outcome, 'init must yield an AsyncCmd, not a drained value');

        $msg = $this->await($outcome->promise);
        self::assertInstanceOf(InviteLinksLoadedMsg::class, $msg);
        self::assertSame($this->linksPayload(), $msg->links);
        self::assertSame('GET', $transport->lastRequest()['method']);
        self::assertStringContainsString('/api/v1/me/invite-links', $transport->lastRequest()['url']);
    }

    public function testUpdateRoutesPayloadMsgsBackIntoTheModel(): void
    {
        // CU-005 clone-mutate law [review console__srphp M1]: payloads land on
        // a NEW model; the old in-place mutation (assertSame) is the bug.
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->linksEnvelope([])));

        [$next, $cmd] = $screen->update(new InviteLinksLoadedMsg($this->linksPayload()));
        self::assertNotSame($screen, $next, 'the screen is immutable: a payload lands on a clone');
        self::assertNull($cmd);
        self::assertFalse($next->isLoading());
        self::assertNull($next->error());
        self::assertSame($this->linksPayload(), $next->items());

        [$afterFailed, $failedCmd] = $next->update(new InviteLinksFailedMsg('nope'));
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

        self::assertInstanceOf(InviteLinksFailedMsg::class, $msg);
        // M11 cure flip (console__copywriter M6): pre-fix, the raw transport
        // tail rode the NetworkError message into this display arm. Post-cure
        // the screen shows the curated network sentence; internals reach the
        // log surface only (ApiClientTest::testTransportFailureBecomesNetworkError).
        self::assertStringContainsString('Could not reach the server', $msg->message);
        self::assertStringNotContainsString('boom', $msg->message);
    }

    // ---- frame rendering (CU-005: the blank-view cure) ---------------------

    public function testViewRendersLoadingThenListWithCursorAndNeverABlankFrame(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->linksEnvelope([])));
        self::assertStringContainsString('Loading invite links', $screen->view(), 'a fresh screen shows its spinner state, not a blank frame');

        [$loaded] = $screen->update(new InviteLinksLoadedMsg($this->linksPayload()));
        $view = $loaded->view();
        self::assertStringContainsString('Invite Links', $view);
        self::assertStringContainsString('https://hub/accept-invite/ABCD', $view, 'rows render the invite URL the hub actually sends');
        self::assertStringContainsString('0/5 used', $view, 'use counters ride the row');
        self::assertStringContainsString('▶', $view, 'the cursor renders on the selected row');
    }

    public function testViewDisclosesTheFailureSentenceAndTheEmptyState(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->linksEnvelope([])));

        [$failed] = $screen->update(new InviteLinksFailedMsg('Could not reach the server.'));
        self::assertStringContainsString('Could not reach the server', $failed->view(), 'the swallow-the-error bug is dead: failures render');

        [$empty] = $screen->update(new InviteLinksLoadedMsg([]));
        self::assertStringContainsString('No invite links yet', $empty->view(), 'the empty state says so instead of vanishing');
    }

    public function testWindowSizeResizesTheFrame(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->linksEnvelope([])));

        [$next, $cmd] = $screen->update(new WindowSizeMsg(120, 40));

        self::assertNotSame($screen, $next);
        self::assertNull($cmd);
        self::assertStringContainsString('Loading invite links', $next->view());
    }

    // ---- keyboard ----------------------------------------------------------

    public function testEscapeIsRoutedByKeyTypeNotRune(): void
    {
        // [review console__docmaint m4] the vendored KeyMsg carries rune '' for
        // Escape; the old rune-match arm was dead code. Escape must navigate
        // back off the frame on the TYPE alone.
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->linksEnvelope([])));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertNotNull($cmd);
        self::assertInstanceOf(NavigateBackMsg::class, $cmd());

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $qCmd());
    }

    public function testArrowsMoveTheCursor(): void
    {
        // [review console__uxdesign C1] selectedIndex never moved at all in the
        // first cut — revoke always hit row 0 no matter where you looked.
        $transport = (new FakeTransport())->json(200, $this->linksEnvelope([]));
        [$screen] = $this->screenWith($transport)->update(new InviteLinksLoadedMsg([
            ['id' => 'inv-1', 'url' => 'https://hub/accept-invite/AAAA'],
            ['id' => 'inv-2', 'url' => 'https://hub/accept-invite/BBBB'],
        ]));

        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex(), 'Down moves the cursor');

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex(), 'Up moves it back');
    }

    // ---- create / revoke ---------------------------------------------------

    public function testCreateKeyReturnsAnAsyncCmdResolvingTheCreatedMsg(): void
    {
        // The hub's 201 carries the created link FLAT; the msg must carry that
        // parsed row, not a screen-fabricated empty placeholder [review
        // console__uxdesign C1].
        $created = ['id' => 'inv-9', 'url' => 'https://hub/accept-invite/WXYZ', 'permission' => 'read', 'max_uses' => 1, 'use_count' => 0];
        $transport = (new FakeTransport())->json(201, $created);
        $screen = $this->screenWith($transport);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertNotNull($cmd);
        $outcome = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $outcome);

        $msg = $this->await($outcome->promise);
        self::assertInstanceOf(InviteLinkCreatedMsg::class, $msg);
        self::assertSame($created, $msg->link, 'the created row crosses the boundary parsed');
        self::assertSame('POST', $transport->lastRequest()['method']);
        self::assertStringContainsString('/api/v1/me/invite-links', $transport->lastRequest()['url']);
    }

    public function testCreatedMsgToastsAndReReadsTheServerList(): void
    {
        $transport = (new FakeTransport())->json(200, $this->linksEnvelope([]));
        $screen = $this->screenWith($transport);

        [$after, $cmd] = $screen->update(new InviteLinkCreatedMsg(['id' => 'inv-9']));
        self::assertNotSame($screen, $after);
        self::assertTrue($after->isLoading(), 'the re-read arms the spinner');

        $batch = $cmd();
        self::assertInstanceOf(BatchMsg::class, $batch);
        self::assertCount(2, $batch->cmds, 'toast + refetch ride one batch');
        self::assertInstanceOf(ShowToastMsg::class, ($batch->cmds[0])());
    }

    public function testRevokedMsgToastsAndReReadsTheServerList(): void
    {
        $transport = (new FakeTransport())->json(200, $this->linksEnvelope([]));
        $screen = $this->screenWith($transport);

        [$after, $cmd] = $screen->update(new InviteLinkRevokedMsg());
        self::assertNotSame($screen, $after);
        self::assertTrue($after->isLoading());

        $batch = $cmd();
        self::assertInstanceOf(BatchMsg::class, $batch);
        self::assertCount(2, $batch->cmds, 'toast + refetch ride one batch');
        self::assertInstanceOf(ShowToastMsg::class, ($batch->cmds[0])());
    }

    public function testRevokeKeyResolvesTheRevokedMsgAndFailureMapsToFailedMsg(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->linksEnvelope($this->linksPayload()))
            ->json(200, ['message' => 'revoked'])
            ->json(200, $this->linksEnvelope($this->linksPayload()))
            ->fail(new \RuntimeException('gone'));
        $screen = $this->loaded($transport);

        [, $revokeCmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        $revoked = $this->runCmd($revokeCmd);
        self::assertInstanceOf(InviteLinkRevokedMsg::class, $revoked);
        self::assertSame('DELETE', $transport->lastRequest()['method']);
        self::assertStringContainsString('/api/v1/me/invite-links/inv-1', $transport->lastRequest()['url']);

        $retryScreen = $this->loaded($transport);
        [, $failCmd] = $retryScreen->update(new KeyMsg(KeyType::Char, 'r'));
        $failed = $this->runCmd($failCmd);
        self::assertInstanceOf(InviteLinksFailedMsg::class, $failed);
        // M11 cure flip: raw revoke-failure tail no longer crosses into display.
        self::assertStringContainsString('Could not reach the server', $failed->message);
        self::assertStringNotContainsString('gone', $failed->message);
    }

    public function testRevokeWithoutLoadedLinksIsANoop(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->linksEnvelope([])));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertNull($cmd);
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
        $transport = (new FakeTransport())->json(200, $this->linksEnvelope($this->linksPayload()));
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

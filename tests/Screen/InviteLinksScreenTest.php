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
use Phlix\Console\Screen\InviteLinksScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Tests\Support\RunCountingLoopSpy;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * M-CONSOLE-36 contract for InviteLinksScreen: hub work rides the OUTER
 * event loop as AsyncCmd messages. The screen must never re-enter
 * `Loop::run()`/`Loop::stop()` itself — Program::scheduleCmd() invokes these
 * closures inside the already-running global loop, and the shared
 * StreamSelectLoop has no re-entry guard (the nested drain's stop() kills
 * the app pump).
 */
final class InviteLinksScreenTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function linksPayload(): array
    {
        return [
            ['id' => 'inv-1', 'code' => 'ABCD', 'url' => 'https://hub/accept-invite/ABCD'],
        ];
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
        $transport = (new FakeTransport())->json(200, ['inviteLinks' => $this->linksPayload()]);
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
        $screen = $this->screenWith((new FakeTransport())->json(200, ['inviteLinks' => []]));

        [$next, $cmd] = $screen->update(new InviteLinksLoadedMsg($this->linksPayload()));
        self::assertSame($screen, $next);
        self::assertNull($cmd);

        [$afterFailed, $failedCmd] = $screen->update(new InviteLinksFailedMsg('nope'));
        self::assertSame($screen, $afterFailed);
        self::assertNull($failedCmd);
    }

    public function testFetchFailureResolvesAFailedMsg(): void
    {
        $transport = (new FakeTransport())->fail(new \RuntimeException('boom'));
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(InviteLinksFailedMsg::class, $msg);
        self::assertStringContainsString('boom', $msg->message);
    }

    // ---- create / revoke ---------------------------------------------------

    public function testCreateKeyReturnsAnAsyncCmdResolvingTheCreatedMsg(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'invite created']);
        $screen = $this->screenWith($transport);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertNotNull($cmd);
        $outcome = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $outcome);

        $msg = $this->await($outcome->promise);
        self::assertInstanceOf(InviteLinkCreatedMsg::class, $msg);
        self::assertSame('POST', $transport->lastRequest()['method']);
        self::assertStringContainsString('/api/v1/me/invite-links', $transport->lastRequest()['url']);
    }

    public function testRevokeKeyResolvesTheRevokedMsgAndFailureMapsToFailedMsg(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['inviteLinks' => $this->linksPayload()])
            ->json(200, ['message' => 'revoked'])
            ->json(200, ['inviteLinks' => $this->linksPayload()])
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
        self::assertStringContainsString('gone', $failed->message);
    }

    public function testRevokeWithoutLoadedLinksIsANoop(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, ['inviteLinks' => []]));

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
        $transport = (new FakeTransport())->json(200, ['inviteLinks' => $this->linksPayload()]);
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

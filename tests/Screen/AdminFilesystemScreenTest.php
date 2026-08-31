<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminFilesystemLoadedMsg;
use Phlix\Console\Screen\AdminFilesystemScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * S406 fix-round guard: the roots view's local '/' sentinel must NEVER ride
 * the wire. The server's fs/browse rail jails a literal '/' against its
 * configured browse roots (403 unless '/' is itself a root) and only the
 * empty-path request answers with the roots list, so `r`-refresh and ←-back
 * onto the roots view must both re-send the no-param request.
 */
final class AdminFilesystemScreenTest extends TestCase
{
    /**
     * Real FsBrowseController wire shapes: the roots view answers with
     * data.path null; a jailed directory echoes its realpath.
     *
     * @return array<string,mixed>
     */
    private function rootsBody(): array
    {
        return [
            'success' => true,
            'data' => [
                'path' => null,
                'parent' => null,
                'entries' => [['name' => 'media', 'path' => '/srv/media']],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function dirBody(): array
    {
        return [
            'success' => true,
            'data' => [
                'path' => '/srv/media',
                'parent' => null,
                'entries' => [],
            ],
        ];
    }

    private function screenWith(FakeTransport $transport): AdminFilesystemScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminFilesystemScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    public function testTheRootsSentinelIsNeverPutOnTheWire(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->rootsBody())
            ->json(200, $this->rootsBody())
            ->json(200, $this->dirBody())
            ->json(200, $this->rootsBody());

        $screen = $this->screenWith($transport);
        $loaded = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminFilesystemLoadedMsg::class, $loaded);
        [$screen] = $screen->update($loaded);
        self::assertSame('/', $screen->currentPath(), 'the roots view shows the local sentinel');
        self::assertStringNotContainsString(
            'path=',
            $transport->requestAt(0)['url'],
            'the opening roots request is param-less',
        );

        // Leg 1: 'r' on the roots view re-fetches via currentPath '/' → the
        // sentinel must be normalized to the NO-PARAM roots request.
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        $loaded = $this->runCmd($cmd);
        self::assertInstanceOf(AdminFilesystemLoadedMsg::class, $loaded);
        [$screen] = $screen->update($loaded);
        self::assertStringContainsString('/api/v1/admin/fs/browse', $transport->requestAt(1)['url']);
        self::assertStringNotContainsString(
            'path=',
            $transport->requestAt(1)['url'],
            "the roots sentinel '/' must not ride the wire — the server jails a literal '/' (403)",
        );

        // Leg 2: a REAL directory still rides as path=… (the normalization is surgical).
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $loaded = $this->runCmd($cmd);
        self::assertInstanceOf(AdminFilesystemLoadedMsg::class, $loaded);
        [$screen] = $screen->update($loaded);
        self::assertSame('/srv/media', $screen->currentPath());
        self::assertStringContainsString('path=%2Fsrv%2Fmedia', $transport->requestAt(2)['url']);

        // Leg 3: ← pops the '/' sentinel off the history; the up-fetch is
        // likewise re-sent as the no-param roots request.
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Left));
        $loaded = $this->runCmd($cmd);
        self::assertInstanceOf(AdminFilesystemLoadedMsg::class, $loaded);
        self::assertSame('/', $loaded->currentPath, 'the header keeps the local sentinel');
        self::assertStringContainsString('/api/v1/admin/fs/browse', $transport->requestAt(3)['url']);
        self::assertStringNotContainsString(
            'path=',
            $transport->requestAt(3)['url'],
            'up-navigation onto the roots sentinel must not send path=/ either',
        );
    }

    // ---- helpers (mirrors the AdminLibrariesScreenTest idiom) ----------

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

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Msg\AdminPluginUpdateActionDoneMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Screen\AdminPluginUpdateScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * [review console__a11y, CU-008] The advertised `c  set channel` on the admin
 * Plugin Updates screen was theater: one press PUT the ALREADY-LOADED channel
 * back and toasted "Channel set to <same>" — the value never changed. The
 * server accepts exactly `stable` or `dev` (PUT
 * /api/v1/admin/plugins/catalog/channel, PluginCatalogController), so the key
 * now arms a two-option picker (s/d, Esc cancels in place) that ships the
 * CHOSEN value.
 */
final class AdminPluginUpdateChannelTest extends TestCase
{
    private const CHANNEL_URL = '/api/v1/admin/plugins/catalog/channel';

    public function testCKeyArmsThePickerWithoutDispatchingAnything(): void
    {
        $transport = new FakeTransport();
        $screen = new AdminPluginUpdateScreen(new AdminClient(new ApiClient('https://srv', $transport)));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));

        self::assertNull($cmd, 'arming the picker sends no request — only the choice does');
        self::assertSame(0, $transport->requestCount(), 'the theater PUT is gone');
        self::assertStringContainsString('cancel', $next->view(), 'the screen now shows the picker it advertises');
        self::assertStringContainsString('stable', $next->view(), 'the picker names both server-legal channels');
        self::assertStringContainsString('dev', $next->view());
    }

    public function testTheDevChoicePutsDevToTheServer(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'ok']);
        $screen = new AdminPluginUpdateScreen(new AdminClient(new ApiClient('https://srv', $transport)));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        [, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'd'));

        self::assertNotNull($cmd, 'the choice dispatches');
        self::assertMsg($this->dispatch($cmd));

        $request = $transport->lastRequest();
        self::assertSame('PUT', $request['method']);
        self::assertStringEndsWith(self::CHANNEL_URL, $request['url']);
        self::assertSame(['channel' => 'dev'], json_decode((string) $request['body'], true), 'the CHOSEN channel ships, never the loaded one');
    }

    public function testTheStableChoicePutsStableAndThePickerClosesOnEscape(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'ok']);
        $screen = new AdminPluginUpdateScreen(new AdminClient(new ApiClient('https://srv', $transport)));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        [$chosen, $cmdS] = $armed->update(new KeyMsg(KeyType::Char, 's'));
        $this->dispatch($cmdS);

        $request = $transport->lastRequest();
        self::assertSame(['channel' => 'stable'], json_decode((string) $request['body'], true));
        self::assertStringNotContainsString('cancel', $chosen->view(), 'the picker is one-shot: the choice closes it');

        [$escaped, $cmdEsc] = $armed->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmdEsc, 'Escape cancels without sending');
        self::assertStringNotContainsString('cancel', $escaped->view());
        self::assertSame(1, $transport->requestCount(), 'arming + one choice + one cancel is exactly one request');
    }

    public function testModifierChordsNeverPickAChannel(): void
    {
        $transport = new FakeTransport();
        $screen = new AdminPluginUpdateScreen(new AdminClient(new ApiClient('https://srv', $transport)));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        [$still, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'd', ctrl: true));
        self::assertNull($cmd, 'ctrl+d is a command chord, not the dev choice');

        [$altStill, $altCmd] = $still->update(new KeyMsg(KeyType::Char, 'd', alt: true));
        self::assertNull($altCmd);
        self::assertSame(0, $transport->requestCount(), 'no chord ships a channel');
        self::assertStringContainsString('cancel', $altStill->view(), 'the picker stays armed until a clean choice or Escape');
    }

    /**
     * [CU-008 fold, same dead-key class] KeyType's enum values are lowercase,
     * so this screen's old $msg->string() comparisons against 'Enter'/'Escape'
     * never matched: after arming the apply-confirm, Enter did not apply,
     * Escape did not cancel, and the 6-codepoint buffer cap blocked 'q' — the
     * screen dead-locked. The arms now key off the enum types and behave as
     * written.
     */
    public function testTheApplyConfirmEnterAppliesAndEscapeCancels(): void
    {
        $transport = (new FakeTransport())->json(200, ['message' => 'ok']);
        $screen = new AdminPluginUpdateScreen(new AdminClient(new ApiClient('https://srv', $transport)));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'u'));
        foreach (str_split('update') as $rune) {
            [$armed] = $armed->update(new KeyMsg(KeyType::Char, $rune));
        }

        [, $applyCmd] = $armed->update(new KeyMsg(KeyType::Enter));
        self::assertNotNull($applyCmd, 'Enter with the word typed must dispatch the apply');
        $done = $this->dispatch($applyCmd);
        self::assertInstanceOf(AdminPluginUpdateActionDoneMsg::class, $done);
        $request = $transport->lastRequest();
        self::assertSame('POST', $request['method']);
        self::assertStringEndsWith('/api/v1/admin/plugins/updates/apply', $request['url']);

        [$confirming] = $screen->update(new KeyMsg(KeyType::Char, 'u'));
        [$cancelled, $cancelCmd] = $confirming->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cancelCmd, 'Escape cancels the confirm, sends nothing');
        self::assertStringNotContainsString('press Enter to confirm', $cancelled->view(), 'and leaves the idle body');
    }

    public function testEscapeBacksOutWhileIdle(): void
    {
        $screen = new AdminPluginUpdateScreen(new AdminClient(new ApiClient('https://srv', new FakeTransport())));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));

        self::assertNotNull($cmd, 'while idle Escape is back-nav — the old string-compare arm never fired');
        self::assertInstanceOf(NavigateBackMsg::class, $this->dispatch($cmd));
    }

    /** The settled AdminPluginUpdateActionDoneMsg — proves the command chain round-trips. */
    private static function assertMsg(?Msg $msg): void
    {
        self::assertInstanceOf(AdminPluginUpdateActionDoneMsg::class, $msg);
    }

    private function dispatch(?\Closure $cmd): ?Msg
    {
        self::assertNotNull($cmd, 'the picker must hand out a command');

        $result = $cmd();
        if ($result instanceof AsyncCmd) {
            return $this->await($result->promise);
        }

        return $result instanceof Msg ? $result : null;
    }

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
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
        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }
        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\Plugin;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminPluginActionDoneMsg;
use Phlix\Console\Msg\AdminPluginActionFailedMsg;
use Phlix\Console\Msg\AdminPluginsFailedMsg;
use Phlix\Console\Msg\AdminPluginsLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AdminPluginsScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Toast\ToastType;

final class AdminPluginsScreenTest extends TestCase
{
    /**
     * The real `GET /api/v1/admin/plugins` shape: the list at the TOP LEVEL, with
     * NO `{success, data}` envelope.
     *
     * Two plugins: trakt (enabled/signed) and lastfm (disabled/unsigned).
     */
    private function pluginsPayload(): array
    {
        return [
            'plugins' => [
                ['name' => 'trakt', 'version' => '1.0', 'type' => 'scrobbler', 'enabled' => true, 'installed_at' => '2026-06-26', 'signed' => true],
                ['name' => 'lastfm', 'version' => '2.0', 'type' => 'scrobbler', 'enabled' => false, 'signed' => false],
            ],
        ];
    }

    private function emptyPlugins(): array
    {
        return ['plugins' => []];
    }

    private function screenWith(FakeTransport $transport): AdminPluginsScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminPluginsScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** Drive init → the loaded Msg, then apply it. */
    private function loaded(FakeTransport $transport): AdminPluginsScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminPluginsLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    /** Type a string into a screen whose install input is open. */
    private function type(Model $model, string $text): Model
    {
        foreach (mb_str_split($text) as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $model;
    }

    // ---- list / loading / error ----------------------------------------

    public function testInitFetchesThePluginList(): void
    {
        $transport = (new FakeTransport())->json(200, $this->pluginsPayload());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminPluginsLoadedMsg::class, $msg);
        self::assertCount(2, $msg->plugins);
        self::assertContainsOnlyInstancesOf(Plugin::class, $msg->plugins);
    }

    public function testLoadingStateBeforePlugins(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->pluginsPayload()));

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading plugins', $screen->view());
    }

    public function testRendersThePluginTable(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));

        self::assertTrue($screen->isLoaded());
        self::assertCount(2, $screen->pluginList());

        $view = $screen->view();
        self::assertStringContainsString('trakt', $view);
        self::assertStringContainsString('lastfm', $view);
        self::assertStringContainsString('Version', $view);
        self::assertStringContainsString('Enabled', $view);
        self::assertStringContainsString('Signed', $view);
        self::assertStringContainsString('2 plugins', $view);
    }

    public function testEmptyListShowsAPlaceholder(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyPlugins()));

        self::assertSame([], $screen->pluginList());
        self::assertStringContainsString('No plugins installed', $screen->view());
    }

    public function testFetchFailureShowsTheErrorAndRetry(): void
    {
        $transport = (new FakeTransport())->json(500, ['error' => 'boom']);
        $screen = $this->screenWith($transport);
        [$failed] = $screen->update($this->runCmd($screen->init()) ?? new AdminPluginsFailedMsg('x'));

        self::assertFalse($failed->isLoaded());
        self::assertNotNull($failed->error());
        $view = $failed->view();
        self::assertStringContainsString('Could not load the plugins', $view);
        self::assertStringContainsString('Press r to retry', $view);
    }

    public function testAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminPluginsScreen(new AdminClient($api), cols: 120, rows: 40);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- selection -----------------------------------------------------

    public function testUpAndDownMoveTheSelectionAndClamp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));
        self::assertSame(0, $screen->selectedIndex());

        [$d1] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $d1->selectedIndex());
        [$d2] = $d1->update(new KeyMsg(KeyType::Down));
        self::assertSame($d1, $d2, 'down at the bottom clamps');

        [$up] = $d1->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex());
    }

    public function testSelectionMoveOnEmptyListIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyPlugins()));

        [$next] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame($screen, $next);
    }

    // ---- enable / disable toggle ---------------------------------------

    public function testEDisablesAnEnabledPlugin(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pluginsPayload())  // init
            ->json(200, ['plugin' => ['name' => 'trakt', 'enabled' => false]])  // disable
            ->json(200, $this->pluginsPayload()); // refetch
        $screen = $this->loaded($transport);

        // trakt (index 0) is enabled → e disables it.
        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($busy->isBusy(), 'an action enters the busy state');
        self::assertStringContainsString('Working', $busy->view());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginActionDoneMsg::class, $done);
        self::assertStringContainsString('disabled', $done->message);
        self::assertStringContainsString('/api/v1/admin/plugins/trakt/disable', $transport->requestAt(1)['url']);

        $msgs = $this->collectCmd($busy->update($done)[1]);
        $toast = $this->firstToast($msgs);
        self::assertSame(ToastType::Success, $toast->type);
        self::assertTrue($this->containsLoaded($msgs), 'the list is refetched after a success');
    }

    public function testEEnablesADisabledPlugin(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pluginsPayload())  // init
            ->json(200, ['plugin' => ['name' => 'lastfm', 'enabled' => true]])  // enable
            ->json(200, $this->pluginsPayload()); // refetch
        $screen = $this->loaded($transport);

        // Move to lastfm (index 1, disabled) → e enables it.
        [$onLastfm] = $screen->update(new KeyMsg(KeyType::Down));
        [, $cmd] = $onLastfm->update(new KeyMsg(KeyType::Char, 'e'));

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginActionDoneMsg::class, $done);
        self::assertStringContainsString('enabled', $done->message);
        self::assertStringContainsString('/api/v1/admin/plugins/lastfm/enable', $transport->requestAt(1)['url']);
    }

    public function testToggleFailureTostsTheServerErrorAndLeavesTheListUnchanged(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pluginsPayload())  // init
            ->json(422, ['error' => 'Plugin failed to enable']); // disable fails
        $screen = $this->loaded($transport);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginActionFailedMsg::class, $failed);
        self::assertSame('Plugin failed to enable', $failed->message);

        [$idle, $batch] = $busy->update($failed);
        self::assertFalse($idle->isBusy(), 'a failed action leaves the busy state');
        self::assertCount(2, $idle->pluginList(), 'the list is unchanged on failure');

        $toast = $this->firstToast($this->collectCmd($batch));
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Plugin failed to enable', $toast->message);
    }

    public function testToggleAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())
            ->json(200, $this->pluginsPayload())
            ->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminPluginsScreen(new AdminClient($api), cols: 120, rows: 40);
        [$loaded] = $screen->update($this->runCmd($screen->init()) ?? new AdminPluginsFailedMsg('x'));

        [, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'e'));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- uninstall confirm flow ----------------------------------------

    public function testXArmsAConfirmThenYUninstalls(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pluginsPayload())  // init
            ->json(204, [])  // uninstall
            ->json(200, $this->emptyPlugins()); // refetch
        $screen = $this->loaded($transport);

        // x arms the confirm — no command yet.
        [$armed, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNull($cmd, 'arming a destructive action fires no command');
        self::assertNotNull($armed->pendingUninstall());
        self::assertSame('trakt', $armed->pendingUninstall()?->name);
        $view = $armed->view();
        self::assertStringContainsString("Uninstall plugin 'trakt'?", $view);
        self::assertStringContainsString('(y/n)', $view);

        // y performs it.
        [$busy, $performCmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());
        self::assertNull($busy->pendingUninstall(), 'performing clears the confirm');
        $done = $this->runCmd($performCmd);
        self::assertInstanceOf(AdminPluginActionDoneMsg::class, $done);
        self::assertStringContainsString('uninstalled', $done->message);
        self::assertSame('DELETE', $transport->requestAt(1)['method']);
    }

    public function testConfirmNCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNotNull($armed->pendingUninstall());

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cmd);
        self::assertNull($cancelled->pendingUninstall(), 'n cancels the confirm');
    }

    public function testConfirmEscCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNotNull($armed->pendingUninstall());

        [$cancelled] = $armed->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cancelled->pendingUninstall(), 'Esc cancels the confirm');
    }

    public function testAnUnrelatedKeyDuringAConfirmIsIgnored(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        [$still, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($armed, $still, 'an unrelated key during a confirm is a no-op');
        self::assertNull($cmd);
    }

    // ---- install-from-URL ----------------------------------------------

    public function testIOpensTheInstallInput(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));

        [$installing, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'i'));
        self::assertTrue($installing->isInstalling());
        self::assertNull($cmd);
        self::assertStringContainsString('Plugin URL', $installing->view());
    }

    public function testInstallSubmitInstallsThePluginAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pluginsPayload())  // init
            ->json(201, ['plugin' => ['name' => 'newplug', 'version' => '0.1', 'type' => 'scrobbler', 'signed' => true]])  // install
            ->json(200, $this->pluginsPayload()); // refetch
        $screen = $this->loaded($transport);

        [$installing] = $screen->update(new KeyMsg(KeyType::Char, 'i'));
        $typed = $this->type($installing, 'https://github.com/owner/repo');

        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));
        self::assertFalse($submitted->isInstalling(), 'the input closes on submit');
        self::assertTrue($submitted->isBusy());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginActionDoneMsg::class, $done);
        self::assertStringContainsString('installed', $done->message);
        self::assertStringContainsString('/api/v1/admin/plugins/install', $transport->requestAt(1)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertSame('https://github.com/owner/repo', $body['url']);

        $msgs = $this->collectCmd($submitted->update($done)[1]);
        self::assertTrue($this->containsLoaded($msgs), 'the list is refetched after install');
    }

    public function testInstallFailureTostsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pluginsPayload())  // init
            ->json(400, ['error' => 'Install URL must be an https:// archive']); // install
        $screen = $this->loaded($transport);

        [$installing] = $screen->update(new KeyMsg(KeyType::Char, 'i'));
        $typed = $this->type($installing, 'http://insecure');
        [$busy, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginActionFailedMsg::class, $failed);
        self::assertSame('Install URL must be an https:// archive', $failed->message);

        [$idle, $batch] = $busy->update($failed);
        self::assertFalse($idle->isBusy());
        $toast = $this->firstToast($this->collectCmd($batch));
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('https://', $toast->message);
    }

    public function testInstallEmptyUrlIsRejectedAtTheBoundary(): void
    {
        // candy-forms' Input is `required()`, so an empty submit does not fire the
        // form's submit; but if it ever did, the boundary guard re-opens the input
        // with an error toast and installs nothing. Submit with no typed value.
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));
        [$installing] = $screen->update(new KeyMsg(KeyType::Char, 'i'));

        [$next] = $installing->update(new KeyMsg(KeyType::Enter));

        // Either the form refuses to submit (still installing) or the boundary
        // re-opened it; in both cases no install request was made and the input is
        // still open.
        self::assertTrue($next->isInstalling(), 'an empty URL never installs');
    }

    public function testInstallEscCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));
        [$installing] = $screen->update(new KeyMsg(KeyType::Char, 'i'));
        self::assertTrue($installing->isInstalling());

        [$cancelled, $cmd] = $installing->update(new KeyMsg(KeyType::Escape));
        self::assertFalse($cancelled->isInstalling(), 'Esc cancels the install input');
        self::assertNull($cmd);
    }

    public function testEmptyUrlBoundaryGuardReopensWithAToast(): void
    {
        // Directly exercise the boundary: a submitted-but-blank form re-opens the
        // input and toasts. We simulate this by submitting whitespace, which the
        // trim() reduces to empty after the form (required) is satisfied by a
        // space. Typing a single space then Enter drives the guard.
        $transport = (new FakeTransport())->json(200, $this->pluginsPayload());
        $screen = $this->loaded($transport);
        [$installing] = $screen->update(new KeyMsg(KeyType::Char, 'i'));

        // A space satisfies `required` (non-empty) but trims to empty at our boundary.
        $typed = $installing->update(new KeyMsg(KeyType::Space));
        $model = $typed[0];
        self::assertInstanceOf(AdminPluginsScreen::class, $model);

        [$next, $cmd] = $model->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($next->isInstalling(), 'the input re-opens on a blank URL');
        $msgs = $this->collectCmd($cmd);
        $toast = $this->firstToast($msgs);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Enter a plugin URL', $toast->message);
        self::assertSame(1, $transport->requestCount(), 'no install request was made');
    }

    // ---- busy / guards -------------------------------------------------

    public function testActionKeysAreIgnoredWhileBusy(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));
        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($busy->isBusy());

        [$still, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame($busy, $still, 'a second action is ignored while busy');
        self::assertNull($cmd);
    }

    public function testActionsOnAnEmptyListAreNoOps(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyPlugins()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertSame($screen, $next, 'no selected plugin → no action');
        self::assertNull($cmd);
    }

    public function testAnUnhandledActionKeyIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testANonActionKeyWithNoConfirmIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- refresh / nav / misc ------------------------------------------

    public function testRRefetchesTheList(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->pluginsPayload())
            ->json(200, $this->pluginsPayload());
        $screen = $this->loaded($transport);

        [$reloading, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertFalse($reloading->isLoaded());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginsLoadedMsg::class, $msg);
    }

    public function testEscapeAndQGoBack(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testResizeReflowsTheScreen(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->pluginsPayload()));

        [$resized, $cmd] = $screen->update(new WindowSizeMsg(80, 24));

        self::assertNull($cmd);
        self::assertStringContainsString('trakt', $resized->view());
    }

    public function testCrumbLabelAndImmutability(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->pluginsPayload()));
        self::assertSame('Plugins', $screen->crumbLabel());

        $crumbed = $screen->withCrumbs(['Admin', 'Plugins']);
        self::assertNotSame($screen, $crumbed);

        $themed = $screen->withTheme(Theme::midnight());
        self::assertNotSame($screen, $themed);
    }

    public function testAnUnhandledMessageIsANoOp(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->pluginsPayload()));

        [$next, $cmd] = $screen->update(new class implements Msg {});

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- helpers -------------------------------------------------------

    private function firstToast(array $msgs): ShowToastMsg
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof ShowToastMsg) {
                return $msg;
            }
        }

        self::fail('expected a ShowToastMsg in the batch');
    }

    /** @param list<Msg> $msgs */
    private function containsLoaded(array $msgs): bool
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof AdminPluginsLoadedMsg) {
                return true;
            }
        }

        return false;
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

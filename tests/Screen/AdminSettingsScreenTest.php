<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminSettingActionDoneMsg;
use Phlix\Console\Msg\AdminSettingActionFailedMsg;
use Phlix\Console\Msg\AdminSettingsFailedMsg;
use Phlix\Console\Msg\AdminSettingsLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AdminSettingsScreen;
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

final class AdminSettingsScreenTest extends TestCase
{
    /** The enveloped `GET .../settings` shape — maps under `data`. */
    private function settingsEnvelope(): array
    {
        return ['success' => true, 'data' => [
            'settings' => [
                'theme' => 'dark',
                'port' => 8096,
                'ratio' => 1.5,
                'debug' => true,
                'hosts' => ['a', 'b'],
            ],
            'types' => [
                'theme' => 'string',
                'port' => 'int',
                'ratio' => 'float',
                'debug' => 'bool',
                'hosts' => 'json',
            ],
            'overridden' => ['port'],
        ]];
    }

    private function emptySettings(): array
    {
        return ['success' => true, 'data' => ['settings' => [], 'types' => [], 'overridden' => []]];
    }

    private function loadTransport(): FakeTransport
    {
        return (new FakeTransport())->json(200, $this->settingsEnvelope());
    }

    private function screenWith(FakeTransport $transport): AdminSettingsScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminSettingsScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** Drive init → the loaded Msg, then apply it. */
    private function loaded(FakeTransport $transport): AdminSettingsScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminSettingsLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    private function type(Model $model, string $text): Model
    {
        foreach (mb_str_split($text) as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $model;
    }

    /** Clear a pre-filled input field then type fresh text. */
    private function retypeField(Model $model, string $text): Model
    {
        for ($i = 0; $i < 32; ++$i) {
            [$model] = $model->update(new KeyMsg(KeyType::Backspace));
        }

        return $this->type($model, $text);
    }

    private function screenWithUnrefreshableToken(FakeTransport $transport): AdminSettingsScreen
    {
        $api = new ApiClient('https://srv', $transport);
        // An empty refresh token means a 401 surfaces AuthError (no retry).
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));

        return new AdminSettingsScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** Select the setting whose key matches (the rows are sorted by key). */
    private function selectKey(AdminSettingsScreen $screen, string $key): AdminSettingsScreen
    {
        $keys = array_map(static fn ($s): string => $s->key, $screen->settingList());
        $index = array_search($key, $keys, true);
        self::assertIsInt($index, "key {$key} is present");
        for ($i = 0; $i < $index; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame($key, $screen->settingList()[$screen->selectedIndex()]->key);

        return $screen;
    }

    // ---- init + render -------------------------------------------------

    public function testInitFetchesTheSettings(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminSettingsLoadedMsg::class, $msg);
        self::assertCount(5, $msg->settings->settings);
        self::assertSame(1, $transport->requestCount());
    }

    public function testLoadingStateBeforeData(): void
    {
        $screen = $this->screenWith($this->loadTransport());

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading settings', $screen->view());
    }

    public function testRendersTheSettingsTableWithEveryTypeAndOverrideMarker(): void
    {
        $screen = $this->loaded($this->loadTransport());

        self::assertTrue($screen->isLoaded());
        $view = $screen->view();
        // keys (sorted: debug, hosts, port, ratio, theme)
        self::assertStringContainsString('theme', $view);
        self::assertStringContainsString('debug', $view);
        self::assertStringContainsString('hosts', $view);
        // values per type
        self::assertStringContainsString('dark', $view);
        self::assertStringContainsString('8096', $view);
        self::assertStringContainsString('1.5', $view);
        self::assertStringContainsString('true', $view);
        self::assertStringContainsString('["a","b"]', $view);
        // an override marker is shown (port is overridden)
        self::assertStringContainsString('✓', $view);
    }

    public function testEmptyStatePlaceholder(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptySettings()));

        self::assertTrue($screen->isLoaded());
        self::assertSame([], $screen->settingList());
        self::assertStringContainsString('No settings', $screen->view());
    }

    public function testFetchFailureShowsTheErrorStateAndRRetries(): void
    {
        $transport = (new FakeTransport())
            ->json(500, ['success' => false, 'error' => 'boom'])
            ->json(200, $this->settingsEnvelope());

        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminSettingsFailedMsg::class, $msg);
        [$failed] = $screen->update($msg);

        self::assertNotNull($failed->error());
        self::assertStringContainsString('Could not load the settings', $failed->view());
        self::assertStringContainsString('Press r to retry', $failed->view());

        // r retries → loads.
        [$retry, $cmd] = $failed->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertFalse($retry->isLoaded());
        $loadedMsg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminSettingsLoadedMsg::class, $loadedMsg);
    }

    public function testFetchAuthErrorSurfacesSessionExpired(): void
    {
        $transport = (new FakeTransport())->json(401, ['success' => false, 'error' => 'Unauthorized']);
        $screen = $this->screenWithUnrefreshableToken($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- selection -----------------------------------------------------

    public function testDownAndUpMoveTheSelectionAndClamp(): void
    {
        $screen = $this->loaded($this->loadTransport());
        self::assertSame(0, $screen->selectedIndex());

        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex());

        // Up at the top is a clamped no-op (same instance).
        [$still] = $up->update(new KeyMsg(KeyType::Up));
        self::assertSame($up, $still);
    }

    public function testSelectionMoveIsANoOpWhenEmpty(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptySettings()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Down));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- bool toggle ---------------------------------------------------

    public function testBoolKeyTogglesImmediatelyAndPutsTheFlippedBool(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(200, ['success' => true, 'message' => 'Settings updated.'])
            ->json(200, $this->settingsEnvelope());

        $screen = $this->selectKey($this->loaded($transport), 'debug');

        // e toggles + PUTs immediately (no form).
        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($busy->isBusy());
        self::assertFalse($busy->isEditing(), 'a bool needs no edit form');

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminSettingActionDoneMsg::class, $done);

        // the debug value was true → the PUT sent a real false.
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['settings' => ['debug' => false]], $body);
        self::assertFalse($body['settings']['debug']);
    }

    public function testRendersTheWorkingStatusLineWhileBusy(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(200, ['success' => true, 'message' => 'Settings updated.']);
        $screen = $this->selectKey($this->loaded($transport), 'debug');

        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertTrue($busy->isBusy());
        self::assertStringContainsString('Working', $busy->view());
    }

    public function testEnterAlsoBeginsEditing(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(200, ['success' => true, 'message' => 'Settings updated.'])
            ->json(200, $this->settingsEnvelope());
        $screen = $this->selectKey($this->loaded($transport), 'debug');

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($busy->isBusy());
        self::assertInstanceOf(AdminSettingActionDoneMsg::class, $this->runCmd($cmd));
    }

    public function testActionDoneToastsAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(200, ['success' => true, 'message' => 'Settings updated.'])
            ->json(200, $this->settingsEnvelope());
        $screen = $this->selectKey($this->loaded($transport), 'debug');
        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        [$after, $cmd] = $busy->update(new AdminSettingActionDoneMsg('Settings updated.'));

        self::assertTrue($after->isBusy(), 'stays busy until the refetch lands');
        $msgs = $this->collectCmd($cmd);
        $toast = array_values(array_filter($msgs, static fn (Msg $m): bool => $m instanceof ShowToastMsg))[0] ?? null;
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Success, $toast->type);
        self::assertStringContainsString('Settings updated.', $toast->message);
        $loaded = array_values(array_filter($msgs, static fn (Msg $m): bool => $m instanceof AdminSettingsLoadedMsg))[0] ?? null;
        self::assertInstanceOf(AdminSettingsLoadedMsg::class, $loaded);
    }

    // ---- non-bool edit (embedded input) --------------------------------

    public function testNonBoolEditOpensThePreFilledInput(): void
    {
        $screen = $this->selectKey($this->loaded($this->loadTransport()), 'theme');

        [$editing, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertTrue($editing->isEditing());
        self::assertSame('theme', $editing->editingKey());
        self::assertNull($cmd);
        // the input is pre-filled with the current value.
        self::assertStringContainsString('dark', $editing->view());
        self::assertStringContainsString("Editing 'theme'", $editing->view());
    }

    public function testStringEditPassesTheInputThroughVerbatim(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(200, ['success' => true, 'message' => 'Settings updated.'])
            ->json(200, $this->settingsEnvelope());

        $screen = $this->selectKey($this->loaded($transport), 'theme');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        // clear the pre-fill then type a new value.
        $typed = $this->retypeField($editing, 'light');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertFalse($submitted->isEditing(), 'the input closes on submit');
        self::assertTrue($submitted->isBusy());
        self::assertInstanceOf(AdminSettingActionDoneMsg::class, $this->runCmd($cmd));

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['settings' => ['theme' => 'light']], $body);
        self::assertIsString($body['settings']['theme']);
    }

    public function testIntEditCoercesToARealInt(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(200, ['success' => true, 'message' => 'Settings updated.'])
            ->json(200, $this->settingsEnvelope());

        $screen = $this->selectKey($this->loaded($transport), 'port');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, '9000');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($submitted->isBusy());
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['settings' => ['port' => 9000]], $body);
        self::assertIsInt($body['settings']['port'], 'an int setting is sent as a real int (not a string)');
    }

    public function testIntEditRejectsANonNumericValueWithNoRequest(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->selectKey($this->loaded($transport), 'port');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->type($editing, 'abc');

        [$reopened, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($reopened->isEditing(), 'the input re-opens on an invalid value');
        self::assertSame('port', $reopened->editingKey());
        self::assertFalse($reopened->isBusy());
        self::assertSame(1, $transport->requestCount(), 'no PUT is sent for an invalid int');
        $msgs = $this->collectCmd($cmd);
        $toast = array_values(array_filter($msgs, static fn (Msg $m): bool => $m instanceof ShowToastMsg))[0] ?? null;
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testFloatEditCoercesToARealFloat(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(200, ['success' => true, 'message' => 'Settings updated.'])
            ->json(200, $this->settingsEnvelope());

        $screen = $this->selectKey($this->loaded($transport), 'ratio');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, '2.25');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($submitted->isBusy());
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(2.25, $body['settings']['ratio']);
        self::assertIsFloat($body['settings']['ratio']);
    }

    public function testFloatEditRejectsANonNumericValueWithNoRequest(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->selectKey($this->loaded($transport), 'ratio');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->type($editing, 'x');

        [$reopened, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($reopened->isEditing());
        self::assertSame(1, $transport->requestCount());
        $msgs = $this->collectCmd($cmd);
        $toast = array_values(array_filter($msgs, static fn (Msg $m): bool => $m instanceof ShowToastMsg))[0] ?? null;
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testJsonEditSendsADecodedArray(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(200, ['success' => true, 'message' => 'Settings updated.'])
            ->json(200, $this->settingsEnvelope());

        $screen = $this->selectKey($this->loaded($transport), 'hosts');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, '["x","y"]');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($submitted->isBusy());
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['x', 'y'], $body['settings']['hosts']);
    }

    public function testJsonEditRejectsANonArrayDecodeWithNoRequest(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->selectKey($this->loaded($transport), 'hosts');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        // a bare number decodes to a scalar, not an array.
        $typed = $this->retypeField($editing, '42');

        [$reopened, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($reopened->isEditing());
        self::assertSame(1, $transport->requestCount(), 'no PUT for a non-array JSON value');
        $msgs = $this->collectCmd($cmd);
        $toast = array_values(array_filter($msgs, static fn (Msg $m): bool => $m instanceof ShowToastMsg))[0] ?? null;
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testJsonEditRejectsInvalidJsonWithNoRequest(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->selectKey($this->loaded($transport), 'hosts');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, 'not json');

        [$reopened, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($reopened->isEditing());
        self::assertSame(1, $transport->requestCount());
        $msgs = $this->collectCmd($cmd);
        self::assertNotEmpty(array_filter($msgs, static fn (Msg $m): bool => $m instanceof ShowToastMsg));
    }

    public function testEditAbortsOnEscapeWithNoRequest(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->selectKey($this->loaded($transport), 'theme');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        [$cancelled, $cmd] = $editing->update(new KeyMsg(KeyType::Escape));

        self::assertFalse($cancelled->isEditing());
        self::assertNull($cancelled->editingKey());
        self::assertNull($cmd);
        self::assertSame(1, $transport->requestCount());
    }

    public function testEditTypingKeepsTheInputOpen(): void
    {
        $screen = $this->selectKey($this->loaded($this->loadTransport()), 'theme');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        $typed = $this->type($editing, 'z');

        self::assertTrue($typed->isEditing());
        self::assertSame('theme', $typed->editingKey());
    }

    // ---- PUT failure ---------------------------------------------------

    public function testPutFailureToastsTheServerErrorAndLeavesTheListUnchanged(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(400, ['success' => false, 'error' => 'Validation failed']);

        $screen = $this->selectKey($this->loaded($transport), 'debug');
        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminSettingActionFailedMsg::class, $failed);

        [$after, $toastCmd] = $busy->update($failed);
        self::assertFalse($after->isBusy());
        self::assertCount(5, $after->settingList(), 'the list is unchanged after a failed PUT');
        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Validation failed', $toast->message);
    }

    public function testPutAuthErrorSurfacesSessionExpired(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(401, ['success' => false, 'error' => 'Unauthorized']); // PUT → 401, no refresh

        $screen = $this->screenWithUnrefreshableToken($transport);
        $loadedMsg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminSettingsLoadedMsg::class, $loadedMsg);
        [$loaded] = $screen->update($loadedMsg);

        $selected = $this->selectKey($loaded, 'debug');
        [, $cmd] = $selected->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    // ---- guards / nav / misc -------------------------------------------

    public function testEditIsIgnoredWhileBusy(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(200, ['success' => true, 'message' => 'Settings updated.']);
        // A bool toggle enters the busy state directly.
        $boolScreen = $this->selectKey($this->loaded($transport), 'debug');
        [$busyBool] = $boolScreen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($busyBool->isBusy());

        // e while busy is a no-op (no new edit/form).
        [$next, $cmd] = $busyBool->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertFalse($next->isEditing());
        self::assertNull($cmd);
    }

    public function testEditIsANoOpWhenTheListIsEmpty(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptySettings()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testRRefreshesTheList(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->settingsEnvelope())
            ->json(200, $this->settingsEnvelope());
        $screen = $this->loaded($transport);

        [$refreshing, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertFalse($refreshing->isLoaded());
        self::assertInstanceOf(AdminSettingsLoadedMsg::class, $this->runCmd($cmd));
    }

    public function testEscapeAndQGoBack(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testAnUnhandledKeyIsANoOp(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'Z'));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testAnUnhandledMessageIsANoOp(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [$next, $cmd] = $screen->update(new class implements Msg {});

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testResizeReflowsTheScreen(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [$resized, $cmd] = $screen->update(new WindowSizeMsg(60, 20));

        self::assertNull($cmd);
        self::assertStringContainsString('theme', $resized->view());
    }

    public function testCrumbLabelAndWithCrumbsAreImmutable(): void
    {
        $screen = $this->loaded($this->loadTransport());
        self::assertSame('Settings', $screen->crumbLabel());

        $crumbed = $screen->withCrumbs(['Admin', 'Settings']);
        self::assertNotSame($screen, $crumbed);
        self::assertStringContainsString('theme', $crumbed->view());
    }

    public function testWithThemeIsImmutableAndRenders(): void
    {
        $screen = $this->loaded($this->loadTransport());
        $themed = $screen->withTheme(Theme::midnight());

        self::assertNotSame($screen, $themed);
        self::assertNotSame('', $themed->view());
    }

    // ---- harness -------------------------------------------------------

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

    /** @return list<Msg> */
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

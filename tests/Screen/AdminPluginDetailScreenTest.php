<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminPluginDetailFailedMsg;
use Phlix\Console\Msg\AdminPluginDetailLoadedMsg;
use Phlix\Console\Msg\AdminPluginSettingFailedMsg;
use Phlix\Console\Msg\AdminPluginSettingSavedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AdminPluginDetailScreen;
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

final class AdminPluginDetailScreenTest extends TestCase
{
    /**
     * The real `GET .../plugins/{name}` shape: top-level `{plugin}`. Fields
     * (schema order): api_key (string, secret, required), debug (bool), port (int),
     * ratio (float), hosts (json), label (string).
     *
     * @return array<string,mixed>
     */
    private function detailBody(): array
    {
        return ['plugin' => [
            'name' => 'trakt',
            'version' => '1.0',
            'type' => 'scrobbler',
            'enabled' => true,
            'installed_at' => '2026-06-26T12:00:00-04:00',
            'settings_schema' => [
                'api_key' => ['type' => 'string', 'required' => true, 'secret' => true, 'label' => 'API Key'],
                'debug' => ['type' => 'bool', 'label' => 'Debug'],
                'port' => ['type' => 'int', 'label' => 'Port'],
                'ratio' => ['type' => 'float', 'label' => 'Ratio'],
                'hosts' => ['type' => 'json', 'label' => 'Hosts'],
                'label' => ['type' => 'string', 'label' => 'Label'],
            ],
            'settings' => [
                'api_key' => '••••',
                'debug' => true,
                'port' => 8096,
                'ratio' => 1.5,
                'hosts' => ['a', 'b'],
                'label' => 'dark',
            ],
        ]];
    }

    private function emptyDetailBody(): array
    {
        return ['plugin' => ['name' => 'bare', 'settings_schema' => [], 'settings' => []]];
    }

    /**
     * A detail whose schema uses the JSON-Schema LONG-form type names straight
     * from a real third-party manifest (e.g. phlix-plugin-trakt): `boolean`,
     * `integer`, `number`, `array`, `object`, plus an unknown type. The client
     * must treat these identically to the short forms.
     *
     * @return array<string,mixed>
     */
    private function longFormDetailBody(): array
    {
        return ['plugin' => [
            'name' => 'trakt',
            'version' => '1.0',
            'type' => 'scrobbler',
            'enabled' => true,
            'settings_schema' => [
                'sync_enabled' => ['type' => 'boolean', 'label' => 'Sync'],
                'interval' => ['type' => 'integer', 'label' => 'Interval'],
                'ratio' => ['type' => 'number', 'label' => 'Ratio'],
                'tags' => ['type' => 'array', 'label' => 'Tags'],
                'extra' => ['type' => 'object', 'label' => 'Extra'],
                'note' => ['type' => 'mystery', 'label' => 'Note', 'description' => 'A free-form note.'],
            ],
            'settings' => [
                'sync_enabled' => true,
                'interval' => 30,
                'ratio' => 1.5,
                'tags' => ['a', 'b'],
                'extra' => ['k' => 'v'],
                'note' => 'hello',
            ],
        ]];
    }

    private function loadTransport(): FakeTransport
    {
        return (new FakeTransport())->json(200, $this->detailBody());
    }

    private function screenWith(FakeTransport $transport): AdminPluginDetailScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminPluginDetailScreen(new AdminClient($api), 'trakt', cols: 120, rows: 40);
    }

    private function screenWithUnrefreshableToken(FakeTransport $transport): AdminPluginDetailScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));

        return new AdminPluginDetailScreen(new AdminClient($api), 'trakt', cols: 120, rows: 40);
    }

    private function loaded(FakeTransport $transport): AdminPluginDetailScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminPluginDetailLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    private function type(Model $model, string $text): Model
    {
        foreach (mb_str_split($text) as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $model;
    }

    private function retypeField(Model $model, string $text): Model
    {
        for ($i = 0; $i < 40; ++$i) {
            [$model] = $model->update(new KeyMsg(KeyType::Backspace));
        }

        return $this->type($model, $text);
    }

    /** Select the field whose key matches (fields are in schema order). */
    private function selectKey(AdminPluginDetailScreen $screen, string $key): AdminPluginDetailScreen
    {
        $keys = array_map(static fn ($f): string => $f->key, $screen->fieldList());
        $index = array_search($key, $keys, true);
        self::assertIsInt($index, "key {$key} is present");
        for ($i = 0; $i < $index; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame($key, $screen->fieldList()[$screen->selectedIndex()]->key);

        return $screen;
    }

    // ---- init + render -------------------------------------------------

    public function testInitFetchesTheDetail(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminPluginDetailLoadedMsg::class, $msg);
        self::assertSame('trakt', $msg->detail->name);
        self::assertCount(6, $msg->detail->fields);
        self::assertSame(1, $transport->requestCount());
    }

    public function testLoadingStateBeforeData(): void
    {
        $screen = $this->screenWith($this->loadTransport());

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading plugin', $screen->view());
    }

    public function testRendersTheHeaderAndFields(): void
    {
        $screen = $this->loaded($this->loadTransport());

        self::assertTrue($screen->isLoaded());
        $view = $screen->view();
        // header
        self::assertStringContainsString('trakt', $view);
        self::assertStringContainsString('v1.0', $view);
        self::assertStringContainsString('scrobbler', $view);
        self::assertStringContainsString('enabled', $view);
        self::assertStringContainsString('installed', $view);
        // field labels + values
        self::assertStringContainsString('API Key', $view);
        self::assertStringContainsString('Port', $view);
        self::assertStringContainsString('8096', $view);
        self::assertStringContainsString('dark', $view);
        // a required marker shows (api_key is required)
        self::assertStringContainsString('✓', $view);
    }

    public function testSecretValueIsShownMaskedNotRaw(): void
    {
        $screen = $this->loaded($this->loadTransport());
        $view = $screen->view();

        self::assertStringContainsString('••••••', $view, 'a secret renders with the mask glyph');
    }

    public function testEmptySettingsPlaceholder(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyDetailBody()));

        self::assertTrue($screen->isLoaded());
        self::assertSame([], $screen->fieldList());
        self::assertStringContainsString('no editable settings', $screen->view());
    }

    public function testFetchFailureShowsTheErrorStateAndRRetries(): void
    {
        $transport = (new FakeTransport())
            ->json(500, ['error' => 'boom'])
            ->json(200, $this->detailBody());

        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminPluginDetailFailedMsg::class, $msg);
        [$failed] = $screen->update($msg);

        self::assertNotNull($failed->error());
        self::assertStringContainsString('Could not load the plugin', $failed->view());
        self::assertStringContainsString('Press r to retry', $failed->view());

        [$retry, $cmd] = $failed->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertFalse($retry->isLoaded());
        self::assertInstanceOf(AdminPluginDetailLoadedMsg::class, $this->runCmd($cmd));
    }

    public function testFetchAuthErrorSurfacesSessionExpired(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
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

        [$still] = $up->update(new KeyMsg(KeyType::Up));
        self::assertSame($up, $still);
    }

    public function testSelectionMoveIsANoOpWhenEmpty(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyDetailBody()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Down));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- bool toggle ---------------------------------------------------

    public function testBoolFieldTogglesImmediatelyAndPutsTheFlippedBoolThenReplacesDetail(): void
    {
        // The PUT returns the refreshed detail with debug flipped to false.
        $refreshed = $this->detailBody();
        $refreshed['plugin']['settings']['debug'] = false;
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(200, $refreshed);

        $screen = $this->selectKey($this->loaded($transport), 'debug');

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($busy->isBusy());
        self::assertFalse($busy->isEditing(), 'a bool needs no edit form');

        $saved = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginSettingSavedMsg::class, $saved);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['settings' => ['debug' => false]], $body);
        self::assertFalse($body['settings']['debug']);

        // applying the saved Msg swaps the refreshed detail in + toasts success.
        [$after, $toastCmd] = $busy->update($saved);
        self::assertFalse($after->isBusy());
        self::assertSame(1, $transport->requestCount() - 1, 'no refetch — the PUT returned the detail');
        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Success, $toast->type);
    }

    public function testEnterAlsoBeginsEditing(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(200, $this->detailBody());
        $screen = $this->selectKey($this->loaded($transport), 'debug');

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($busy->isBusy());
        self::assertInstanceOf(AdminPluginSettingSavedMsg::class, $this->runCmd($cmd));
    }

    public function testRendersTheWorkingStatusLineWhileBusy(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(200, $this->detailBody());
        $screen = $this->selectKey($this->loaded($transport), 'debug');

        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertTrue($busy->isBusy());
        self::assertStringContainsString('Working', $busy->view());
    }

    // ---- non-bool edit (embedded input) --------------------------------

    public function testNonBoolEditOpensThePreFilledInput(): void
    {
        $screen = $this->selectKey($this->loaded($this->loadTransport()), 'label');

        [$editing, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertTrue($editing->isEditing());
        self::assertSame('label', $editing->editingKey());
        self::assertNull($cmd);
        self::assertStringContainsString('dark', $editing->view());
        self::assertStringContainsString("Editing 'Label'", $editing->view());
    }

    public function testStringEditPassesTheInputThroughVerbatim(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(200, $this->detailBody());

        $screen = $this->selectKey($this->loaded($transport), 'label');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, 'light');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertFalse($submitted->isEditing());
        self::assertTrue($submitted->isBusy());
        self::assertInstanceOf(AdminPluginSettingSavedMsg::class, $this->runCmd($cmd));

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['settings' => ['label' => 'light']], $body);
        self::assertIsString($body['settings']['label']);
    }

    public function testIntEditCoercesToARealInt(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(200, $this->detailBody());

        $screen = $this->selectKey($this->loaded($transport), 'port');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, '9000');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($submitted->isBusy());
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['settings' => ['port' => 9000]], $body);
        self::assertIsInt($body['settings']['port']);
    }

    public function testIntEditRejectsANonNumericValueWithNoRequest(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->selectKey($this->loaded($transport), 'port');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->type($editing, 'abc');

        [$reopened, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($reopened->isEditing());
        self::assertSame('port', $reopened->editingKey());
        self::assertFalse($reopened->isBusy());
        self::assertSame(1, $transport->requestCount(), 'no PUT for an invalid int');
        $toast = $this->firstToast($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testFloatEditCoercesToARealFloat(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(200, $this->detailBody());

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
        $toast = $this->firstToast($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testJsonEditSendsADecodedArray(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(200, $this->detailBody());

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
        $typed = $this->retypeField($editing, '42');

        [$reopened, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($reopened->isEditing());
        self::assertSame(1, $transport->requestCount());
        $toast = $this->firstToast($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    // ---- JSON-Schema long-form types -----------------------------------

    public function testLongFormBooleanTogglesImmediatelyWithNoTextInput(): void
    {
        // The PUT returns the refreshed detail with sync_enabled flipped to false.
        $refreshed = $this->longFormDetailBody();
        $refreshed['plugin']['settings']['sync_enabled'] = false;
        $transport = (new FakeTransport())
            ->json(200, $this->longFormDetailBody())
            ->json(200, $refreshed);

        $screen = $this->selectKey($this->loaded($transport), 'sync_enabled');

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($busy->isBusy());
        self::assertFalse($busy->isEditing(), 'a boolean field needs no text input');

        self::assertInstanceOf(AdminPluginSettingSavedMsg::class, $this->runCmd($cmd));

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['settings' => ['sync_enabled' => false]], $body);
        self::assertFalse($body['settings']['sync_enabled']);
    }

    public function testLongFormIntegerCoercesToARealInt(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->longFormDetailBody())
            ->json(200, $this->longFormDetailBody());

        $screen = $this->selectKey($this->loaded($transport), 'interval');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, '45');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($submitted->isBusy());
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['settings' => ['interval' => 45]], $body);
        self::assertIsInt($body['settings']['interval']);
    }

    public function testLongFormIntegerRejectsANonNumericValueWithNoRequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->longFormDetailBody());
        $screen = $this->selectKey($this->loaded($transport), 'interval');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, 'abc');

        [$reopened, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($reopened->isEditing(), 'an invalid integer re-opens the input');
        self::assertSame('interval', $reopened->editingKey());
        self::assertFalse($reopened->isBusy());
        self::assertSame(1, $transport->requestCount(), 'no PUT for an invalid integer');
        $toast = $this->firstToast($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testLongFormNumberCoercesToARealFloat(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->longFormDetailBody())
            ->json(200, $this->longFormDetailBody());

        $screen = $this->selectKey($this->loaded($transport), 'ratio');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, '3.5');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($submitted->isBusy());
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(3.5, $body['settings']['ratio']);
        self::assertIsFloat($body['settings']['ratio']);
    }

    public function testLongFormArrayDecodesToAnArray(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->longFormDetailBody())
            ->json(200, $this->longFormDetailBody());

        $screen = $this->selectKey($this->loaded($transport), 'tags');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, '["x","y"]');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($submitted->isBusy());
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['x', 'y'], $body['settings']['tags']);
    }

    public function testLongFormObjectDecodesToAnArray(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->longFormDetailBody())
            ->json(200, $this->longFormDetailBody());

        $screen = $this->selectKey($this->loaded($transport), 'extra');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, '{"k":"w"}');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($submitted->isBusy());
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['k' => 'w'], $body['settings']['extra']);
    }

    public function testLongFormObjectRejectsANonArrayDecodeWithNoRequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->longFormDetailBody());
        $screen = $this->selectKey($this->loaded($transport), 'extra');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->retypeField($editing, '42');

        [$reopened, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($reopened->isEditing());
        self::assertSame(1, $transport->requestCount());
        $toast = $this->firstToast($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testUnknownTypePassesTheInputThroughAsAString(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->longFormDetailBody())
            ->json(200, $this->longFormDetailBody());

        $screen = $this->selectKey($this->loaded($transport), 'note');
        // an unknown type opens a text input (not a toggle).
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($editing->isEditing());
        self::assertStringContainsString('A free-form note.', $editing->view(), 'the field description shows in the edit body');
        $typed = $this->retypeField($editing, 'world');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($submitted->isBusy());
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['settings' => ['note' => 'world']], $body);
        self::assertIsString($body['settings']['note']);
    }

    // ---- secret edit ---------------------------------------------------

    public function testSecretEditPreFillsBlankNotTheMaskedValue(): void
    {
        $screen = $this->selectKey($this->loaded($this->loadTransport()), 'api_key');

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertTrue($editing->isEditing());
        self::assertSame('api_key', $editing->editingKey());
        $view = $editing->view();
        self::assertStringContainsString('leave blank to keep', $view);
        self::assertStringNotContainsString('••••', $view, 'the masked value is never pre-filled into the input');
    }

    public function testBlankSecretSubmitIsANoOpWithNoRequest(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->selectKey($this->loaded($transport), 'api_key');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        // submit without typing anything (the field is blank).
        [$after, $cmd] = $editing->update(new KeyMsg(KeyType::Enter));

        self::assertFalse($after->isEditing(), 'the input closes');
        self::assertFalse($after->isBusy());
        self::assertNull($cmd);
        self::assertSame(1, $transport->requestCount(), 'a blank secret never re-sends the masked value');
    }

    public function testNonBlankSecretSubmitPutsTheNewValue(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(200, $this->detailBody());

        $screen = $this->selectKey($this->loaded($transport), 'api_key');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $typed = $this->type($editing, 'freshsecret');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($submitted->isBusy());
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['settings' => ['api_key' => 'freshsecret']], $body);
    }

    // ---- edit cancel / typing ------------------------------------------

    public function testEditAbortsOnEscapeWithNoRequest(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->selectKey($this->loaded($transport), 'label');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        [$cancelled, $cmd] = $editing->update(new KeyMsg(KeyType::Escape));

        self::assertFalse($cancelled->isEditing());
        self::assertNull($cancelled->editingKey());
        self::assertNull($cmd);
        self::assertSame(1, $transport->requestCount());
    }

    public function testEditTypingKeepsTheInputOpen(): void
    {
        $screen = $this->selectKey($this->loaded($this->loadTransport()), 'label');
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        $typed = $this->type($editing, 'z');

        self::assertTrue($typed->isEditing());
        self::assertSame('label', $typed->editingKey());
    }

    // ---- PUT failure ---------------------------------------------------

    public function testPutFailureToastsTheServerErrorAndLeavesTheDetailUnchanged(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(400, ['error' => 'Invalid setting', 'code' => 'plugin.settings.invalid']);

        $screen = $this->selectKey($this->loaded($transport), 'debug');
        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginSettingFailedMsg::class, $failed);

        [$after, $toastCmd] = $busy->update($failed);
        self::assertFalse($after->isBusy());
        self::assertCount(6, $after->fieldList(), 'the detail is unchanged after a failed PUT');
        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Invalid setting', $toast->message);
    }

    public function testPutAuthErrorSurfacesSessionExpired(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(401, ['error' => 'Unauthorized']);

        $screen = $this->screenWithUnrefreshableToken($transport);
        $loadedMsg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminPluginDetailLoadedMsg::class, $loadedMsg);
        [$loaded] = $screen->update($loadedMsg);

        $selected = $this->selectKey($loaded, 'debug');
        [, $cmd] = $selected->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    // ---- guards / nav / misc -------------------------------------------

    public function testEditIsIgnoredWhileBusy(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(200, $this->detailBody());
        $screen = $this->selectKey($this->loaded($transport), 'debug');
        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($busy->isBusy());

        [$next, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertFalse($next->isEditing());
        self::assertNull($cmd);
    }

    public function testEditIsANoOpWhenEmpty(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyDetailBody()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testRRefreshesTheDetail(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->detailBody())
            ->json(200, $this->detailBody());
        $screen = $this->loaded($transport);

        [$refreshing, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertFalse($refreshing->isLoaded());
        self::assertInstanceOf(AdminPluginDetailLoadedMsg::class, $this->runCmd($cmd));
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
        self::assertStringContainsString('Port', $resized->view());
    }

    public function testCrumbLabelAndWithCrumbsAreImmutable(): void
    {
        $screen = $this->loaded($this->loadTransport());
        self::assertSame('Plugin', $screen->crumbLabel());

        $crumbed = $screen->withCrumbs(['Admin', 'Plugins', 'Plugin']);
        self::assertNotSame($screen, $crumbed);
        self::assertStringContainsString('Port', $crumbed->view());
    }

    public function testWithThemeIsImmutableAndRenders(): void
    {
        $screen = $this->loaded($this->loadTransport());
        $themed = $screen->withTheme(Theme::midnight());

        self::assertNotSame($screen, $themed);
        self::assertNotSame('', $themed->view());
    }

    public function testDetailAccessorReturnsTheLoadedDetail(): void
    {
        $screen = $this->loaded($this->loadTransport());

        $detail = $screen->detail();
        self::assertNotNull($detail);
        self::assertSame('trakt', $detail->name);
    }

    public function testHeaderFallsBackToTheNameWhenNoDetailIsLoaded(): void
    {
        $screen = $this->screenWith($this->loadTransport());
        // Before load the header falls back to the constructor name (loading body).
        self::assertNull($screen->detail());
    }

    // ---- harness -------------------------------------------------------

    private function firstToast(?\Closure $cmd): ?Msg
    {
        foreach ($this->collectCmd($cmd) as $m) {
            if ($m instanceof ShowToastMsg) {
                return $m;
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

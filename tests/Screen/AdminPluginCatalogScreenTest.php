<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\CatalogPlugin;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminPluginCatalogActionDoneMsg;
use Phlix\Console\Msg\AdminPluginCatalogActionFailedMsg;
use Phlix\Console\Msg\AdminPluginCatalogFailedMsg;
use Phlix\Console\Msg\AdminPluginCatalogLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AdminPluginCatalogScreen;
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

final class AdminPluginCatalogScreenTest extends TestCase
{
    /**
     * The real `GET /api/v1/admin/plugins/catalog` shape: the whole body TOP-LEVEL.
     *
     * Two catalogs: A (trakt, NOT installed) and B (lastfm, installed). Catalog B
     * carries a per-source error.
     *
     * @return array<string,mixed>
     */
    private function catalogPayload(): array
    {
        return [
            'default_source' => 'https://a.com/catalog.json',
            'sources' => ['https://a.com/catalog.json', 'https://b.com/catalog.json'],
            'catalogs' => [
                ['source' => 'https://a.com/catalog.json', 'name' => 'A', 'plugins' => [
                    ['name' => 'trakt', 'title' => 'Trakt', 'type' => 'scrobbler', 'author' => 'Alice', 'repo' => 'https://github.com/owner/trakt', 'installed' => false, 'enabled' => false],
                ]],
                ['source' => 'https://b.com/catalog.json', 'name' => 'B', 'plugins' => [
                    ['name' => 'lastfm', 'title' => 'Last.fm', 'type' => 'scrobbler', 'author' => 'Bob', 'repo' => 'https://github.com/owner/lastfm', 'installed' => true, 'enabled' => true],
                ]],
            ],
            'errors' => ['https://b.com/catalog.json could not be fetched'],
        ];
    }

    /** @return array<string,mixed> */
    private function emptyCatalog(): array
    {
        return ['default_source' => '', 'sources' => [], 'catalogs' => [], 'errors' => []];
    }

    private function screenWith(FakeTransport $transport): AdminPluginCatalogScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminPluginCatalogScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** A screen whose token has an empty refresh token (a 401 cannot be retried). */
    private function screenWithNoRefresh(FakeTransport $transport): AdminPluginCatalogScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));

        return new AdminPluginCatalogScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** Drive init → the loaded Msg, then apply it. */
    private function loaded(FakeTransport $transport): AdminPluginCatalogScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminPluginCatalogLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    private function type(Model $model, string $text): Model
    {
        foreach (mb_str_split($text) as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $model;
    }

    // ---- list / loading / error ----------------------------------------

    public function testInitFetchesTheCatalog(): void
    {
        $transport = (new FakeTransport())->json(200, $this->catalogPayload());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminPluginCatalogLoadedMsg::class, $msg);
        self::assertCount(2, $msg->result->flatPlugins());
    }

    public function testLoadingStateBeforeCatalog(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->catalogPayload()));

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading catalog', $screen->view());
    }

    public function testRendersTheCatalogTableWithFlagsAndAuthor(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        self::assertTrue($screen->isLoaded());
        self::assertCount(2, $screen->pluginList());

        $view = $screen->view();
        self::assertStringContainsString('Trakt', $view);
        self::assertStringContainsString('Last.fm', $view);
        self::assertStringContainsString('Alice', $view);
        self::assertStringContainsString('Bob', $view);
        self::assertStringContainsString('Installed', $view);
        self::assertStringContainsString('Enabled', $view);
    }

    public function testHeaderShowsTheSourcesAndPerSourceErrors(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        $view = $screen->view();
        self::assertStringContainsString('Sources:', $view);
        self::assertStringContainsString('https://a.com/catalog.json', $view);
        self::assertStringContainsString('could not be fetched', $view, 'per-source errors show in the header');
    }

    public function testEmptyCatalogPlaceholder(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyCatalog()));

        self::assertSame([], $screen->pluginList());
        self::assertStringContainsString('No catalog entries', $screen->view());
        self::assertStringContainsString('(none)', $screen->view());
    }

    public function testFetchFailureShowsAnErrorAndRetry(): void
    {
        $transport = (new FakeTransport())
            ->json(500, ['error' => 'boom'])
            ->json(200, $this->catalogPayload());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminPluginCatalogFailedMsg::class, $msg);

        [$errored] = $screen->update($msg);
        self::assertNotNull($errored->error());
        self::assertStringContainsString('retry', $errored->view());

        [, $retryCmd] = $errored->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertInstanceOf(AdminPluginCatalogLoadedMsg::class, $this->runCmd($retryCmd));
    }

    public function testFetchAuthErrorSurfacesASessionExpiry(): void
    {
        // An empty refresh token means the 401 cannot be retried, so the AuthError
        // propagates (the established pattern for forcing a session expiry).
        $screen = $this->screenWithNoRefresh((new FakeTransport())->json(401, ['error' => 'expired']));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($screen->init()));
    }

    // ---- selection -----------------------------------------------------

    public function testSelectionMovesAndClamps(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        self::assertSame(0, $screen->selectedIndex());
        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());
        [$clamped] = $down->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $clamped->selectedIndex(), 'down clamps at the last entry');
        [$up] = $clamped->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex());
    }

    public function testSelectionIsANoOpWhenEmpty(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyCatalog()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- install-from-catalog ------------------------------------------

    public function testEnterArmsInstallForANotInstalledEntry(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        [$armed, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertNull($cmd);
        self::assertNotNull($armed->pendingInstall());
        self::assertSame('trakt', $armed->pendingInstall()?->name);
        self::assertStringContainsString('Install', $armed->view());
        self::assertStringContainsString('https://github.com/owner/trakt', $armed->view(), 'the confirm shows the repo URL');
    }

    public function testIKeyAlsoArmsInstall(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'i'));

        self::assertNotNull($armed->pendingInstall());
    }

    public function testInstallConfirmYInstallsViaTheRepoUrlAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())  // init
            ->json(201, ['plugin' => ['name' => 'trakt', 'version' => '1.0', 'type' => 'scrobbler', 'signed' => true]])  // install
            ->json(200, $this->catalogPayload()); // refetch
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Enter));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        self::assertTrue($busy->isBusy());
        self::assertNull($busy->pendingInstall(), 'confirming clears the armed install');

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginCatalogActionDoneMsg::class, $done);
        // The install used the entry's repo, NOT a url field.
        self::assertSame('POST', $transport->requestAt(1)['method']);
        self::assertStringContainsString('/api/v1/admin/plugins/install', $transport->requestAt(1)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertSame('https://github.com/owner/trakt', $body['url']);

        $msgs = $this->collectCmd($busy->update($done)[1]);
        self::assertTrue($this->containsLoaded($msgs), 'the catalog is refetched after install');
    }

    public function testInstallConfirmNCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Enter));

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cmd);
        self::assertNull($cancelled->pendingInstall());
    }

    public function testInstallConfirmEscCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Enter));

        [$cancelled] = $armed->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cancelled->pendingInstall());
    }

    public function testAnUnrelatedKeyDuringTheInstallConfirmIsIgnored(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Enter));

        [$still, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($armed, $still);
        self::assertNull($cmd);
    }

    public function testAlreadyInstalledEntryShowsAnInfoToastAndFiresNoRequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->catalogPayload());
        $screen = $this->loaded($transport);
        [$onLastfm] = $screen->update(new KeyMsg(KeyType::Down)); // select lastfm (installed)

        [$same, $cmd] = $onLastfm->update(new KeyMsg(KeyType::Enter));

        self::assertNull($same->pendingInstall(), 'an installed entry never arms');
        $toast = $this->firstToast([$this->runCmd($cmd)]);
        self::assertSame(ToastType::Info, $toast->type);
        self::assertStringContainsString('already installed', $toast->message);
        self::assertCount(1, $transport->requests, 'no install request was made');
    }

    public function testInstallFailureToastsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())  // init
            ->json(422, ['error' => 'Plugin signature invalid']); // install
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Enter));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginCatalogActionFailedMsg::class, $failed);
        self::assertSame('Plugin signature invalid', $failed->message);

        [$idle, $toastCmd] = $busy->update($failed);
        self::assertFalse($idle->isBusy());
        $toast = $this->firstToast($this->collectCmd($toastCmd));
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('signature', $toast->message);
    }

    public function testInstallAuthErrorSurfacesASessionExpiry(): void
    {
        // An empty refresh token means the install 401 cannot be retried.
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())  // init
            ->json(401, ['error' => 'expired']);  // install
        $base = $this->screenWithNoRefresh($transport);
        $loadedMsg = $this->runCmd($base->init());
        self::assertInstanceOf(AdminPluginCatalogLoadedMsg::class, $loadedMsg);
        $screen = $base->update($loadedMsg)[0];

        [$armed] = $screen->update(new KeyMsg(KeyType::Enter));
        [, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    public function testEnterIsANoOpWhenNoEntryIsSelected(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyCatalog()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- add source ----------------------------------------------------

    public function testAOpensTheAddSourceInput(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        [$adding, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertTrue($adding->isAddingSource());
        self::assertNull($cmd);
        self::assertStringContainsString('Catalog source URL', $adding->view());
    }

    public function testAddSourceSubmitPostsAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())  // init
            ->json(200, ['sources' => ['https://a.com/catalog.json', 'https://new.com/catalog.json']])  // add
            ->json(200, $this->catalogPayload()); // refetch
        $screen = $this->loaded($transport);

        [$adding] = $screen->update(new KeyMsg(KeyType::Char, 'a'));
        $typed = $this->type($adding, 'https://new.com/catalog.json');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertFalse($submitted->isAddingSource(), 'the input closes on submit');
        self::assertTrue($submitted->isBusy());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginCatalogActionDoneMsg::class, $done);
        self::assertSame('POST', $transport->requestAt(1)['method']);
        self::assertStringContainsString('/api/v1/admin/plugins/catalog/sources', $transport->requestAt(1)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertSame('https://new.com/catalog.json', $body['url']);

        $msgs = $this->collectCmd($submitted->update($done)[1]);
        self::assertTrue($this->containsLoaded($msgs), 'the catalog is refetched after add');
    }

    public function testAddSourceEmptyUrlIsRejectedAtTheBoundary(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));
        [$adding] = $screen->update(new KeyMsg(KeyType::Char, 'a'));

        [$next] = $adding->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($next->isAddingSource(), 'an empty URL never adds a source');
    }

    public function testAddSourceEscCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));
        [$adding] = $screen->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertTrue($adding->isAddingSource());

        [$cancelled] = $adding->update(new KeyMsg(KeyType::Escape));
        self::assertFalse($cancelled->isAddingSource());
    }

    public function testAddSourceFailureToastsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())  // init
            ->json(400, ['error' => 'Source URL is unreachable']); // add
        $screen = $this->loaded($transport);

        [$adding] = $screen->update(new KeyMsg(KeyType::Char, 'a'));
        $typed = $this->type($adding, 'http://nope');
        [$busy, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginCatalogActionFailedMsg::class, $failed);

        [$idle, $toastCmd] = $busy->update($failed);
        self::assertFalse($idle->isBusy());
        $toast = $this->firstToast($this->collectCmd($toastCmd));
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('unreachable', $toast->message);
    }

    // ---- remove source -------------------------------------------------

    public function testXArmsRemovalOfTheSelectedEntrysSource(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        [$armed, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        self::assertNull($cmd);
        self::assertSame('https://a.com/catalog.json', $armed->pendingRemoveSource(), 'trakt belongs to source A');
        self::assertStringContainsString('Remove catalog source', $armed->view());
    }

    public function testXResolvesTheSourceOfAnEntryInALaterCatalog(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));
        [$onLastfm] = $screen->update(new KeyMsg(KeyType::Down)); // select lastfm (catalog B)

        [$armed] = $onLastfm->update(new KeyMsg(KeyType::Char, 'x'));

        self::assertSame('https://b.com/catalog.json', $armed->pendingRemoveSource(), 'lastfm belongs to source B');
    }

    public function testRemoveSourceConfirmYDeletesAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())  // init
            ->json(200, ['sources' => ['https://b.com/catalog.json']])  // remove
            ->json(200, $this->catalogPayload()); // refetch
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        self::assertTrue($busy->isBusy());
        self::assertNull($busy->pendingRemoveSource());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginCatalogActionDoneMsg::class, $done);
        self::assertSame('DELETE', $transport->requestAt(1)['method']);
        self::assertStringContainsString('/api/v1/admin/plugins/catalog/sources', $transport->requestAt(1)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertSame('https://a.com/catalog.json', $body['url']);

        $msgs = $this->collectCmd($busy->update($done)[1]);
        self::assertTrue($this->containsLoaded($msgs), 'the catalog is refetched after removal');
    }

    public function testRemoveSourceConfirmNCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cmd);
        self::assertNull($cancelled->pendingRemoveSource());
    }

    public function testRemoveSourceConfirmEscCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        [$cancelled] = $armed->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cancelled->pendingRemoveSource());
    }

    public function testAnUnrelatedKeyDuringTheRemoveConfirmIsIgnored(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        [$still, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($armed, $still);
        self::assertNull($cmd);
    }

    public function testRemoveSourceFailureToastsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())  // init
            ->json(400, ['error' => 'Unknown source']); // remove
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPluginCatalogActionFailedMsg::class, $failed);

        [$idle, $toastCmd] = $busy->update($failed);
        self::assertFalse($idle->isBusy());
        $toast = $this->firstToast($this->collectCmd($toastCmd));
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testXIsANoOpWhenNoEntryIsSelected(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyCatalog()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- busy guard / refresh / nav ------------------------------------

    public function testActionKeysAreGuardedWhileBusy(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())  // init
            ->json(201, ['plugin' => ['name' => 'trakt']]);  // install (kept pending)
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Enter));
        [$busy] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());

        // i / x are ignored while busy; a is allowed (opens the input).
        [$stillI, $iCmd] = $busy->update(new KeyMsg(KeyType::Char, 'i'));
        self::assertSame($busy, $stillI);
        self::assertNull($iCmd);
        [$stillX, $xCmd] = $busy->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame($busy, $stillX);
        self::assertNull($xCmd);
    }

    public function testRRefetchesTheCatalog(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())
            ->json(200, $this->catalogPayload());
        $screen = $this->loaded($transport);

        [$refreshing, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertFalse($refreshing->isLoaded(), 'refresh resets the loaded flag');
        self::assertInstanceOf(AdminPluginCatalogLoadedMsg::class, $this->runCmd($cmd));
    }

    public function testEscAndQNavigateBack(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testAnUnhandledCharKeyIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testResizeReflowsTheScreen(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        [$resized, $cmd] = $screen->update(new WindowSizeMsg(80, 24));
        self::assertNull($cmd);
        self::assertStringContainsString('Trakt', $resized->view());
    }

    public function testCrumbLabelAndImmutability(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->catalogPayload()));
        self::assertSame('Catalog', $screen->crumbLabel());

        $crumbed = $screen->withCrumbs(['Admin', 'Plugins', 'Catalog']);
        self::assertNotSame($screen, $crumbed);

        $themed = $screen->withTheme(Theme::midnight());
        self::assertNotSame($screen, $themed);
    }

    public function testAnUnhandledMessageIsANoOp(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->catalogPayload()));

        [$next, $cmd] = $screen->update(new class implements Msg {});
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testResultAccessorReturnsTheLoadedResult(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        self::assertSame('https://a.com/catalog.json', $screen->result()->defaultSource);
    }

    public function testEnterIsANoOpWhileBusy(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())  // init
            ->json(201, ['plugin' => ['name' => 'trakt']]);  // install kept pending
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Enter));
        [$busy] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());

        [$still, $cmd] = $busy->update(new KeyMsg(KeyType::Enter));
        self::assertSame($busy, $still, 'Enter cannot arm a new install while busy');
        self::assertNull($cmd);
    }

    public function testBusyStatusLineShowsWorking(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->catalogPayload())  // init
            ->json(201, ['plugin' => ['name' => 'trakt']]);  // install kept pending
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Enter));
        [$busy] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        self::assertStringContainsString('Working', $busy->view());
    }

    public function testANonHandledKeyTypeIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->catalogPayload()));

        // A key whose type is none of Escape/Up/Down/Enter/Char (e.g. Tab).
        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- helpers -------------------------------------------------------

    /** @param list<?Msg> $msgs */
    private function firstToast(array $msgs): ShowToastMsg
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof ShowToastMsg) {
                return $msg;
            }
        }

        self::fail('expected a ShowToastMsg');
    }

    /** @param list<Msg> $msgs */
    private function containsLoaded(array $msgs): bool
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof AdminPluginCatalogLoadedMsg) {
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

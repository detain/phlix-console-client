<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\PortForwardCandidate;
use Phlix\Console\Msg\AdminPortForwardCandidatesFailedMsg;
use Phlix\Console\Msg\AdminPortForwardCandidatesLoadedMsg;
use Phlix\Console\Msg\AdminRemoteActionDoneMsg;
use Phlix\Console\Msg\AdminRemoteActionFailedMsg;
use Phlix\Console\Msg\AdminRemoteFailedMsg;
use Phlix\Console\Msg\AdminRemoteStatusLoadedMsg;
use Phlix\Console\Msg\HubPairingDoneMsg;
use Phlix\Console\Msg\HubPairingExpiredMsg;
use Phlix\Console\Msg\HubPairingPollSucceededMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Screen\AdminRemoteAccessScreen;
use Phlix\Console\Tests\Api\FakeClockLoop;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Toast\ToastType;

final class AdminRemoteAccessScreenTest extends TestCase
{
    private const PANEL_HUB = 0;
    private const PANEL_SUBDOMAIN = 1;
    private const PANEL_RELAY = 2;
    private const PANEL_PORTFORWARD = 3;

    /** The four status payloads, all active (paired / claimed / connected / enabled). */
    private function activePayloads(): array
    {
        return [
            ['paired' => true, 'serverId' => 'srv-9', 'hubUrl' => 'https://hub.example', 'enrolledAt' => '2026-06-26T12:00:00+00:00'],
            ['claimed' => true, 'subdomain' => 'myserver', 'fqdn' => 'myserver.phlix.tv'],
            ['connected' => true, 'active' => true, 'establishedAt' => '2026-06-26T10:00:00+00:00'],
            ['enabled' => true, 'method' => 'upnp', 'externalIp' => '203.0.113.7', 'externalPort' => 32400, 'hostname' => 'home.example.com'],
        ];
    }

    /** The four status payloads, all inactive. */
    private function inactivePayloads(): array
    {
        return [
            ['paired' => false],
            ['claimed' => false],
            ['connected' => false, 'active' => false],
            ['enabled' => false],
        ];
    }

    /** Script a transport with the four status GETs (hub, subdomain, relay, portforward). */
    private function statusTransport(array $payloads): FakeTransport
    {
        $transport = new FakeTransport();
        foreach ($payloads as $payload) {
            $transport->json(200, $payload);
        }

        return $transport;
    }

    private function screenWith(FakeTransport $transport): AdminRemoteAccessScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminRemoteAccessScreen(new AdminClient($api), cols: 120, rows: 44);
    }

    private function screenWithNoRefresh(FakeTransport $transport): AdminRemoteAccessScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));

        return new AdminRemoteAccessScreen(new AdminClient($api), cols: 120, rows: 44);
    }

    /** Drive init → loaded Msg, then apply it. */
    private function loaded(FakeTransport $transport): AdminRemoteAccessScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminRemoteStatusLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    /** A screen loaded with the active payloads and the panel moved to $panel. */
    private function loadedActiveOn(int $panel): AdminRemoteAccessScreen
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));
        for ($i = 0; $i < $panel; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame($panel, $screen->selectedPanel());

        return $screen;
    }

    // ---- init / loading / error ----------------------------------------

    public function testInitFetchesTheFourStatuses(): void
    {
        $transport = $this->statusTransport($this->activePayloads());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminRemoteStatusLoadedMsg::class, $msg);
        self::assertTrue($msg->status->hub->paired);
        self::assertSame(4, $transport->requestCount());
    }

    public function testLoadingStateBeforeStatus(): void
    {
        $screen = $this->screenWith($this->statusTransport($this->activePayloads()));

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading remote-access status', $screen->view());
    }

    public function testFetchFailureShowsAnErrorAndRetryHint(): void
    {
        // A relay-leg 500 rejects the whole fan-out → error state.
        $transport = (new FakeTransport())
            ->json(200, ['paired' => false])
            ->json(200, ['claimed' => false])
            ->json(500, ['success' => false, 'message' => 'boom'])
            ->json(200, ['enabled' => false]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminRemoteFailedMsg::class, $msg);

        [$next] = $screen->update($msg);
        self::assertFalse($next->isLoaded());
        self::assertNotNull($next->error());
        self::assertStringContainsString('Could not load', $next->view());
        self::assertStringContainsString('Press r to retry', $next->view());
    }

    public function testFetchAuthErrorSurfacesASessionExpiry(): void
    {
        $transport = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $screen = $this->screenWithNoRefresh($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- panel render --------------------------------------------------

    public function testRendersAllFourPanelsActive(): void
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));

        self::assertTrue($screen->isLoaded());
        $view = $screen->view();
        self::assertStringContainsString('Hub Pairing', $view);
        self::assertStringContainsString('Paired', $view);
        self::assertStringContainsString('https://hub.example', $view);
        self::assertStringContainsString('Subdomain', $view);
        self::assertStringContainsString('myserver.phlix.tv', $view);
        self::assertStringContainsString('Relay Tunnel', $view);
        self::assertStringContainsString('Connected', $view);
        self::assertStringContainsString('Port Forward', $view);
        self::assertStringContainsString('203.0.113.7', $view);
        self::assertStringContainsString('32400', $view);
    }

    public function testRendersAllFourPanelsInactive(): void
    {
        $screen = $this->loaded($this->statusTransport($this->inactivePayloads()));

        $view = $screen->view();
        self::assertStringContainsString('Not paired', $view);
        self::assertStringContainsString('Press p to pair with a hub', $view, 'the pairing wizard is in the console');
        self::assertStringContainsString('No subdomain claimed', $view);
        self::assertStringContainsString('Disconnected', $view);
        self::assertStringContainsString('Port forwarding disabled', $view);
    }

    // ---- panel selection -----------------------------------------------

    public function testDownAndUpMoveTheSelectedPanelAndClamp(): void
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));
        self::assertSame(self::PANEL_HUB, $screen->selectedPanel());

        // Up at the top is a clamped no-op (same instance).
        [$atTop] = $screen->update(new KeyMsg(KeyType::Up));
        self::assertSame($screen, $atTop);

        [$s1] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(self::PANEL_SUBDOMAIN, $s1->selectedPanel());
        [$s2] = $s1->update(new KeyMsg(KeyType::Down));
        [$s3] = $s2->update(new KeyMsg(KeyType::Down));
        self::assertSame(self::PANEL_PORTFORWARD, $s3->selectedPanel());

        // Down at the bottom is a clamped no-op.
        [$atBottom] = $s3->update(new KeyMsg(KeyType::Down));
        self::assertSame($s3, $atBottom);

        [$back] = $s3->update(new KeyMsg(KeyType::Up));
        self::assertSame(self::PANEL_RELAY, $back->selectedPanel());
    }

    public function testTheHintReflectsTheSelectedPanelActions(): void
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));

        // Hub (paired) → unenroll.
        self::assertStringContainsString('u  unenroll', $screen->view());

        [$sub] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertStringContainsString('x  release', $sub->view());

        [$relay] = $sub->update(new KeyMsg(KeyType::Down));
        $relayView = $relay->view();
        self::assertStringContainsString('e  enable', $relayView);
        self::assertStringContainsString('p  ping', $relayView);

        [$pf] = $relay->update(new KeyMsg(KeyType::Down));
        $pfView = $pf->view();
        self::assertStringContainsString('e  enable', $pfView);
        self::assertStringContainsString('d  disable', $pfView);
    }

    public function testHintForAnUnpairedHubNotesTheWebAdmin(): void
    {
        $screen = $this->loaded($this->statusTransport($this->inactivePayloads()));

        // Hub panel selected, unpaired → no unenroll, points at the web admin.
        self::assertStringContainsString('Press p to pair with a hub', $screen->view());
        self::assertStringNotContainsString('u  unenroll', $screen->view());

        // Subdomain (unclaimed) → claim.
        [$sub] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertStringContainsString('c  claim', $sub->view());
    }

    // ---- relay actions -------------------------------------------------

    public function testRelayEnablePostsToastsAndRefetches(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());   // init (relay disconnected)
        $transport->json(200, ['success' => true]);                       // relay/enable
        foreach ($this->activePayloads() as $p) {                         // refetch (now active)
            $transport->json(200, $p);
        }
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_RELAY);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($busy->isBusy());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminRemoteActionDoneMsg::class, $done);
        self::assertSame('Relay enabled', $done->message);
        self::assertStringContainsString('/api/v1/admin/remote/relay/enable', $transport->requestAt(4)['url']);

        [$working, $batch] = $busy->update($done);
        self::assertTrue($working->isBusy());
        $msgs = $this->collectCmd($batch);

        $toast = $this->firstOf($msgs, ShowToastMsg::class);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Success, $toast->type);
        self::assertStringContainsString('Relay enabled', $toast->message);

        $loaded = $this->firstOf($msgs, AdminRemoteStatusLoadedMsg::class);
        self::assertInstanceOf(AdminRemoteStatusLoadedMsg::class, $loaded);
        [$after] = $working->update($loaded);
        self::assertNotNull($after->status());
        self::assertTrue($after->status()->relay->connected);
    }

    public function testRelayDisablePostsTheRightPath(): void
    {
        $screen = $this->loadedActiveOn(self::PANEL_RELAY);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'd'));
        $done = $this->runCmd($cmd);

        self::assertInstanceOf(AdminRemoteActionDoneMsg::class, $done);
        self::assertSame('Relay disabled', $done->message);
    }

    public function testRelayPingToastsTheLatency(): void
    {
        $transport = $this->statusTransport($this->activePayloads());
        $transport->json(200, ['success' => true, 'latencyMs' => 42]);
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_RELAY);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        $done = $this->runCmd($cmd);

        self::assertInstanceOf(AdminRemoteActionDoneMsg::class, $done);
        self::assertStringContainsString('42ms', $done->message);
        self::assertStringContainsString('/api/v1/admin/remote/relay/ping', $transport->requestAt(4)['url']);
    }

    public function testRelayKeysAreNoOpsOnOtherPanels(): void
    {
        // `e` on the Hub panel does nothing (relay-only key).
        $screen = $this->loadedActiveOn(self::PANEL_HUB);

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testRelayUnknownKeyIsANoOp(): void
    {
        // A key that is not e/d/p on the Relay panel falls through to a no-op.
        $screen = $this->loadedActiveOn(self::PANEL_RELAY);

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- port-forward actions ------------------------------------------

    public function testPortForwardEnablePostsTheRightPath(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['success' => true]);
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_PORTFORWARD);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $done = $this->runCmd($cmd);

        self::assertInstanceOf(AdminRemoteActionDoneMsg::class, $done);
        self::assertSame('Port forwarding enabled', $done->message);
        self::assertStringContainsString('/api/v1/admin/remote/portforward/enable', $transport->requestAt(4)['url']);
    }

    public function testPortForwardDisablePostsTheRightPath(): void
    {
        $screen = $this->loadedActiveOn(self::PANEL_PORTFORWARD);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'd'));
        $done = $this->runCmd($cmd);

        self::assertInstanceOf(AdminRemoteActionDoneMsg::class, $done);
        self::assertSame('Port forwarding disabled', $done->message);
    }

    public function testPortForwardUnknownKeyIsANoOp(): void
    {
        $screen = $this->loadedActiveOn(self::PANEL_PORTFORWARD);

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- port-forward candidates sub-view ------------------------------

    /** Two discovered candidates (the `{candidates:[…]}` top-level shape). */
    private function candidatesPayload(): array
    {
        return [
            'candidates' => [
                ['hostname' => 'http://192.168.1.100:32400', 'externalIp' => '203.0.113.7', 'port' => 32400],
                ['hostname' => 'http://10.0.0.5:8096', 'externalIp' => '198.51.100.4', 'port' => 8096],
            ],
        ];
    }

    public function testCOnThePortForwardPanelOpensTheCandidatesSubViewAndFetches(): void
    {
        $transport = $this->statusTransport($this->activePayloads());
        $transport->json(200, $this->candidatesPayload());
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_PORTFORWARD);

        // `c` opens the sub-view (loading) and fires the candidates GET.
        [$opening, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertTrue($opening->isCandidatesOpen());
        self::assertFalse($opening->isCandidatesLoaded());
        self::assertStringContainsString('Loading port-forward candidates', $opening->view());
        self::assertNotNull($cmd);

        $loaded = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPortForwardCandidatesLoadedMsg::class, $loaded);
        self::assertStringContainsString('/api/v1/admin/remote/portforward/candidates', $transport->requestAt(4)['url']);
        self::assertSame('GET', $transport->requestAt(4)['method']);

        // The panel selection is untouched — the status underneath is unchanged.
        [$ready] = $opening->update($loaded);
        self::assertTrue($ready->isCandidatesLoaded());
        self::assertSame(self::PANEL_PORTFORWARD, $ready->selectedPanel());
        self::assertNotNull($ready->status(), 'the four-panel status is untouched by the sub-view');
        self::assertCount(2, $ready->candidatesList());
    }

    public function testTheCandidatesSubViewRendersTheDiscoveredRows(): void
    {
        $ready = $this->openedCandidates($this->candidatesPayload());

        $view = $ready->view();
        self::assertStringContainsString('Port-forward candidates', $view, 'the sub-view has its own title');
        self::assertStringContainsString('Discovered: 2 candidates', $view);
        self::assertStringContainsString('192.168.1.100', $view);
        self::assertStringContainsString('203.0.113.7', $view);
        self::assertStringContainsString('32400', $view);
        self::assertStringContainsString('c/Esc  close', $view);
    }

    public function testTheCandidatesSubViewShowsTheEmptyPlaceholder(): void
    {
        $ready = $this->openedCandidates(['candidates' => []]);

        self::assertTrue($ready->isCandidatesLoaded());
        self::assertSame([], $ready->candidatesList());
        self::assertStringContainsString('No candidates discovered.', $ready->view());
    }

    public function testCClosesTheCandidatesSubViewWithoutPoppingOrChangingPanel(): void
    {
        $ready = $this->openedCandidates($this->candidatesPayload());

        [$closed, $cmd] = $ready->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertFalse($closed->isCandidatesOpen());
        self::assertNull($cmd, 'closing the sub-view fires no command (no NavigateBack)');
        self::assertSame(self::PANEL_PORTFORWARD, $closed->selectedPanel());
        self::assertStringContainsString('Port Forward', $closed->view());
    }

    public function testEscapeClosesTheCandidatesSubViewWithoutPopping(): void
    {
        $ready = $this->openedCandidates($this->candidatesPayload());

        [$closed, $cmd] = $ready->update(new KeyMsg(KeyType::Escape));
        self::assertFalse($closed->isCandidatesOpen());
        self::assertNull($cmd, 'Esc closes the sub-view rather than navigating back');
        self::assertSame(self::PANEL_PORTFORWARD, $closed->selectedPanel());
    }

    public function testTheCandidatesSubViewScrollsAndClamps(): void
    {
        $ready = $this->openedCandidates($this->candidatesPayload());
        self::assertSame(0, $ready->candidatesSelectedIndex());

        // Up at the top is a clamped no-op (same instance).
        [$atTop] = $ready->update(new KeyMsg(KeyType::Up));
        self::assertSame($ready, $atTop);

        [$down] = $ready->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->candidatesSelectedIndex());

        // Down at the bottom is a clamped no-op.
        [$atBottom] = $down->update(new KeyMsg(KeyType::Down));
        self::assertSame($down, $atBottom);
    }

    public function testScrollingAnEmptyCandidatesListIsANoOp(): void
    {
        $ready = $this->openedCandidates(['candidates' => []]);
        self::assertSame([], $ready->candidatesList());

        [$down] = $ready->update(new KeyMsg(KeyType::Down));
        self::assertSame($ready, $down, 'scrolling an empty list is a no-op');
        [$up] = $ready->update(new KeyMsg(KeyType::Up));
        self::assertSame($ready, $up);
    }

    public function testRRefetchesTheCandidatesWhileTheSubViewIsOpen(): void
    {
        $transport = $this->statusTransport($this->activePayloads());
        $transport->json(200, $this->candidatesPayload());   // first open
        $transport->json(200, ['candidates' => []]);          // `r` refetch
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_PORTFORWARD);

        [$opening, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        $ready = $opening->update($this->runCmd($cmd))[0];
        self::assertCount(2, $ready->candidatesList());

        // `r` re-opens in the loading state and refetches.
        [$reloading, $rCmd] = $ready->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertTrue($reloading->isCandidatesOpen());
        self::assertFalse($reloading->isCandidatesLoaded());

        $reloaded = $this->runCmd($rCmd);
        self::assertInstanceOf(AdminPortForwardCandidatesLoadedMsg::class, $reloaded);
        [$after] = $reloading->update($reloaded);
        self::assertSame([], $after->candidatesList());
    }

    public function testAnUnrelatedKeyInTheCandidatesSubViewIsANoOp(): void
    {
        $ready = $this->openedCandidates($this->candidatesPayload());

        [$next, $cmd] = $ready->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($ready, $next);
        self::assertNull($cmd);
    }

    public function testCandidatesFetchFailureShowsAnError(): void
    {
        $transport = $this->statusTransport($this->activePayloads());
        $transport->json(500, ['success' => false, 'message' => 'Failed to discover candidates.']);
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_PORTFORWARD);

        [$opening, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPortForwardCandidatesFailedMsg::class, $msg);

        [$failed] = $opening->update($msg);
        self::assertTrue($failed->isCandidatesOpen());
        self::assertFalse($failed->isCandidatesLoaded());
        self::assertNotNull($failed->candidatesError());
        self::assertStringContainsString('Could not load the port-forward candidates', $failed->view());
        self::assertStringContainsString('Press r to retry', $failed->view());
    }

    public function testCandidatesFetchAuthErrorSurfacesASessionExpiry(): void
    {
        $transport = $this->statusTransport($this->activePayloads());
        $transport->json(401, ['error' => 'Unauthorized']);   // candidates, no refresh
        $screen = $this->screenWithNoRefresh($transport);
        $loaded = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminRemoteStatusLoadedMsg::class, $loaded);
        $screen = $screen->update($loaded)[0];
        for ($i = 0; $i < self::PANEL_PORTFORWARD; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        $msg = $this->runCmd($cmd);

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testCandidatesLoadedIsDroppedWhenTheSubViewIsClosed(): void
    {
        // A candidates fetch resolving after the sub-view closed must be ignored.
        $screen = $this->loadedActiveOn(self::PANEL_PORTFORWARD);

        [$ignored, $cmd] = $screen->update(new AdminPortForwardCandidatesLoadedMsg([
            new PortForwardCandidate('http://h:1', 'ip', 1),
        ]));
        self::assertSame($screen, $ignored, 'a late candidates load is dropped when the sub-view is closed');
        self::assertNull($cmd);
        self::assertFalse($ignored->isCandidatesOpen());
    }

    public function testCIsTheClaimKeyOnTheSubdomainPanelNotCandidates(): void
    {
        // Panel-scoped keys: `c` on the SUBDOMAIN panel still claims (it does NOT
        // open the candidates sub-view, which is Port-Forward-only).
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['success' => true, 'fqdn' => 'myserver.phlix.tv']);
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_SUBDOMAIN);

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertFalse($next->isCandidatesOpen(), '`c` here claims, it does not open candidates');
        self::assertTrue($next->isBusy());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminRemoteActionDoneMsg::class, $done);
        self::assertStringContainsString('/api/v1/admin/remote/subdomain/claim', $transport->requestAt(4)['url']);
    }

    public function testCIsANoOpOnTheRelayPanelWhereNothingIsBound(): void
    {
        // `c` is not bound on the Relay panel — a plain no-op, no sub-view.
        $screen = $this->loadedActiveOn(self::PANEL_RELAY);

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
        self::assertFalse($next->isCandidatesOpen());
    }

    public function testCDoesNotOpenCandidatesWhileBusy(): void
    {
        // The candidates `c` runs through the action path, which is gated on `busy`.
        $screen = $this->loadedActiveOn(self::PANEL_PORTFORWARD);

        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertTrue($busy->isBusy());

        [$same, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertSame($busy, $same);
        self::assertNull($cmd);
        self::assertFalse($same->isCandidatesOpen());
    }

    // ---- subdomain claim / release -------------------------------------

    public function testSubdomainClaimWhenUnclaimedPosts(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['success' => true, 'fqdn' => 'myserver.phlix.tv']);
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_SUBDOMAIN);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        $done = $this->runCmd($cmd);

        self::assertInstanceOf(AdminRemoteActionDoneMsg::class, $done);
        self::assertStringContainsString('myserver.phlix.tv', $done->message);
        self::assertStringContainsString('/api/v1/admin/remote/subdomain/claim', $transport->requestAt(4)['url']);
    }

    public function testSubdomainClaimIsANoOpWhenAlreadyClaimed(): void
    {
        // Active payload = claimed → `c` does nothing.
        $screen = $this->loadedActiveOn(self::PANEL_SUBDOMAIN);

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testSubdomainReleaseArmsAConfirmThenPerformsOnY(): void
    {
        $transport = $this->statusTransport($this->activePayloads());   // init (claimed)
        $transport->json(200, ['success' => true]);                     // release
        foreach ($this->inactivePayloads() as $p) {                     // refetch
            $transport->json(200, $p);
        }
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_SUBDOMAIN);

        // `x` arms the confirm — NO request fired yet.
        [$armed, $armCmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNull($armCmd, 'arming fires no command');
        self::assertSame('release', $armed->pendingConfirm());
        self::assertStringContainsString('Release the subdomain?', $armed->view());
        self::assertSame(4, $transport->requestCount(), 'no release request while merely armed');

        // `y` performs.
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertNull($busy->pendingConfirm());
        self::assertTrue($busy->isBusy());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminRemoteActionDoneMsg::class, $done);
        self::assertSame('Subdomain released', $done->message);
        self::assertStringContainsString('/api/v1/admin/remote/subdomain/release', $transport->requestAt(4)['url']);
    }

    public function testSubdomainReleaseConfirmCancelsOnN(): void
    {
        $screen = $this->loadedActiveOn(self::PANEL_SUBDOMAIN);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame('release', $armed->pendingConfirm());

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cancelled->pendingConfirm());
        self::assertNull($cmd, 'cancelling fires no request');
    }

    public function testReleaseConfirmIsCancelledByEscape(): void
    {
        $screen = $this->loadedActiveOn(self::PANEL_SUBDOMAIN);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Escape));

        self::assertNull($cancelled->pendingConfirm());
        self::assertNull($cmd, 'Esc during a confirm cancels rather than navigating back');
    }

    // ---- hub pairing wizard --------------------------------------------

    /** Type $text into the wizard's hub URL buffer, one key at a time. */
    private function typedHubUrl(AdminRemoteAccessScreen $screen, string $text): AdminRemoteAccessScreen
    {
        foreach (str_split($text) as $char) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Char, $char));
        }

        return $screen;
    }

    public function testPairingRendersTheClaimCodeOnTheLiveScreen(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        $typing = $this->typedHubUrl($waiting, 'hub.example');
        [$working, $cmd] = $typing->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(HubPairingDoneMsg::class, $msg);
        self::assertSame('ABCD-1234', $msg->claimCode);

        // The screen the program holds after the claim arrives is the one that shows the code.
        [$live, $pollCmd] = $working->update($msg);
        self::assertStringContainsString('Claim code: ABCD-1234', $live->view());
        self::assertStringNotContainsString('Hub URL:', $live->view());
        self::assertNotNull($pollCmd);
    }

    public function testAPairingRequestErrorToastsTheRealServerMessage(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(409, ['success' => false, 'message' => 'Hub refused the pairing request: URL not allowed.']);
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        $typing = $this->typedHubUrl($waiting, 'hub.example');
        [$working, $cmd] = $typing->update(new KeyMsg(KeyType::Enter));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(HubPairingExpiredMsg::class, $msg);
        self::assertSame('Hub refused the pairing request: URL not allowed.', $msg->reason);

        [$after, $toastCmd] = $working->update($msg);
        self::assertFalse($after->isBusy(), 'the request busy state is cleared on failure');
        self::assertStringNotContainsString('Hub URL:', $after->view(), 'the wizard closes');

        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('URL not allowed', $toast->message);
        self::assertStringNotContainsString('Pairing failed.', $toast->message);
    }

    public function testEscDuringThePairingPollLeavesTheScreenIdle(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling] = $working->update($this->runCmd($cmd));
        self::assertFalse($polling->isBusy(), 'the claim clears the request busy flag');

        [$cancelled] = $polling->update(new KeyMsg(KeyType::Escape));
        self::assertFalse($cancelled->isBusy(), 'Esc during POLLING leaves the screen idle');
        self::assertStringNotContainsString('Working', $cancelled->view());

        // The panel actions are live again, so `p` reopens the wizard instead of being dropped.
        [$reopened] = $cancelled->update(new KeyMsg(KeyType::Char, 'p'));
        self::assertStringContainsString('Hub URL:', $reopened->view());
    }

    public function testThePollExpiryReasonReachesTheToast(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $transport->json(410, ['error' => 'Claim has expired.']);
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling, $pollCmd] = $working->update($this->runCmd($cmd));

        $expired = $this->runCmd($pollCmd);
        self::assertInstanceOf(HubPairingExpiredMsg::class, $expired);
        self::assertSame('Claim has expired.', $expired->reason);

        [$after, $toastCmd] = $polling->update($expired);
        self::assertStringNotContainsString('Claim code', $after->view(), 'the wizard closes');

        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Claim has expired.', $toast->message);
        self::assertStringNotContainsString('Pairing failed.', $toast->message);
    }

    public function testAPollFailureClosesTheWizardOnAScreenThatWasResizedMidPoll(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        $claim = $this->runCmd($cmd);
        self::assertInstanceOf(HubPairingDoneMsg::class, $claim);
        [$polling] = $working->update($claim);
        [$resized] = $polling->update(new WindowSizeMsg(100, 30));
        self::assertStringContainsString('Claim code: ABCD-1234', $resized->view());

        // The poll's terminal outcome (timeout or final network failure) arrives as this message.
        [$after, $toastCmd] = $resized->update(new HubPairingExpiredMsg($claim->attempt, 'Pairing timed out.'));
        self::assertStringNotContainsString('Claim code', $after->view(), 'the live wizard closes');
        self::assertFalse($after->isBusy());

        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertStringContainsString('Pairing timed out.', $toast->message);
    }

    public function testEscDuringTheRequestDropsTheClaimWhenItArrives(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($working->isBusy(), 'the request is in flight');

        // Esc lands before the hub answers: the wizard closes and the request is abandoned.
        [$cancelled] = $working->update(new KeyMsg(KeyType::Escape));
        self::assertStringNotContainsString('Hub URL:', $cancelled->view());

        // The claim arrives late. It must not reopen the wizard or start a poll.
        $claim = $this->runCmd($cmd);
        self::assertInstanceOf(HubPairingDoneMsg::class, $claim);
        [$after, $pollCmd] = $cancelled->update($claim);
        self::assertNull($pollCmd, 'no poll runs for a cancelled attempt');
        self::assertStringNotContainsString('Claim code', $after->view());
        self::assertStringNotContainsString('Hub URL:', $after->view());
        self::assertFalse($after->isBusy(), 'the cancelled request does not leave the screen busy');
    }

    public function testASecondEnterWhileTheRequestIsInFlightDispatchesNoSecondClaim(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        self::assertNotNull($cmd, 'the first Enter dispatches the claim request');
        self::assertTrue($working->isBusy(), 'the request is in flight');

        // The wizard stays on the URL prompt while the request runs, so a second Enter
        // must be swallowed rather than dispatched under the same attempt.
        [$again, $secondCmd] = $working->update(new KeyMsg(KeyType::Enter));
        self::assertSame($working, $again);
        self::assertNull($secondCmd, 'a second Enter while in flight dispatches no second request');

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(HubPairingDoneMsg::class, $msg);
        [$live, $pollCmd] = $working->update($msg);
        self::assertStringContainsString('Claim code: ABCD-1234', $live->view());
        self::assertNotNull($pollCmd);
    }

    public function testAPollOutcomeFromACancelledAttemptDoesNotTouchTheReopenedWizard(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $transport->json(410, ['error' => 'Claim has expired.']);
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling, $pollCmd] = $working->update($this->runCmd($cmd));

        // Esc during POLLING, then `p` opens a second wizard while poll A is still running.
        [$cancelled] = $polling->update(new KeyMsg(KeyType::Escape));
        [$reopened] = $cancelled->update(new KeyMsg(KeyType::Char, 'p'));
        self::assertStringContainsString('Hub URL:', $reopened->view());

        // Poll A ends now; its expiry must neither close wizard B nor toast over it.
        $stale = $this->runCmd($pollCmd);
        self::assertInstanceOf(HubPairingExpiredMsg::class, $stale);
        [$after, $toastCmd] = $reopened->update($stale);
        self::assertNull($toastCmd, 'a stale outcome shows no toast');
        self::assertStringContainsString('Hub URL:', $after->view(), 'the live wizard stays open');
    }

    public function testAPollRejectionKeepsTheServersOwnMessage(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        // The poll answers non-2xx with the friendly text in `message`, not `error`.
        $transport->json(410, ['success' => false, 'message' => 'Claim expired at 13:00 UTC']);
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling, $pollCmd] = $working->update($this->runCmd($cmd));

        // The expiry is recognised on the first poll, so there is no retry and no 'Request failed (HTTP 410)'.
        $expired = $this->runCmd($pollCmd);
        self::assertInstanceOf(HubPairingExpiredMsg::class, $expired);
        self::assertSame('Claim expired at 13:00 UTC', $expired->reason);

        [, $toastCmd] = $polling->update($expired);
        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertStringContainsString('Claim expired at 13:00 UTC', $toast->message);
        self::assertStringNotContainsString('HTTP 410', $toast->message);
    }

    public function testASuccessFromACancelledAttemptStillRefetchesTheStatusesButKeepsTheReopenedWizard(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $transport->json(200, ['paired' => true, 'serverId' => 'srv-9']);
        foreach ($this->activePayloads() as $payload) {                 // refetch after the pairing
            $transport->json(200, $payload);
        }
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling, $pollCmd] = $working->update($this->runCmd($cmd));

        // Esc during POLLING, then `p` reopens the wizard while poll A is still running.
        [$cancelled] = $polling->update(new KeyMsg(KeyType::Escape));
        [$reopened] = $cancelled->update(new KeyMsg(KeyType::Char, 'p'));
        self::assertStringContainsString('Hub URL:', $reopened->view());

        // Poll A finds the hub paired. The attempt is stale, but the statuses must still refetch.
        $paired = $this->runCmd($pollCmd);
        self::assertInstanceOf(HubPairingPollSucceededMsg::class, $paired);
        [$after, $fetchCmd] = $reopened->update($paired);
        self::assertNotNull($fetchCmd, 'a stale success still refetches the statuses');
        self::assertStringContainsString('Hub URL:', $after->view(), 'the live wizard stays open');

        $status = $this->runCmd($fetchCmd);
        self::assertInstanceOf(AdminRemoteStatusLoadedMsg::class, $status);
        self::assertTrue($status->status->hub->paired);
    }

    public function testAStaleSuccessRefetchDoesNotReleaseTheBusyFlagOfTheReopenedRequest(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $transport->json(200, ['paired' => true, 'serverId' => 'srv-9']);
        foreach ($this->activePayloads() as $payload) {                 // refetch after the pairing
            $transport->json(200, $payload);
        }
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling, $pollCmd] = $working->update($this->runCmd($cmd));

        // Esc during POLLING, `p` reopens, and Enter sends a second claim that is still in flight.
        [$cancelled] = $polling->update(new KeyMsg(KeyType::Escape));
        [$reopened] = $cancelled->update(new KeyMsg(KeyType::Char, 'p'));
        [$inFlight, $secondCmd] = $this->typedHubUrl($reopened, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        self::assertNotNull($secondCmd, 'the reopened wizard sends its own claim');
        self::assertTrue($inFlight->isBusy(), 'the reopened claim is in flight');

        // Poll A reports paired, and its refetch settles while the reopened claim is still out.
        [$afterPaired, $fetchCmd] = $inFlight->update($this->runCmd($pollCmd));
        [$afterStatus] = $afterPaired->update($this->runCmd($fetchCmd));
        self::assertTrue($afterStatus->isBusy(), 'the refetch does not release the reopened claim');
        self::assertStringContainsString('Hub URL:', $afterStatus->view(), 'the live wizard stays open');

        [, $duplicateCmd] = $afterStatus->update(new KeyMsg(KeyType::Enter));
        self::assertNull($duplicateCmd, 'a second Enter while the reopened claim is in flight dispatches nothing');
    }

    public function testAClaimFromAnEarlierScreenInstanceDoesNotMatchThisScreensAttempt(): void
    {
        // Two screens are loaded in turn, so the status payloads are queued for both.
        $transport = $this->statusTransport(array_merge($this->inactivePayloads(), $this->inactivePayloads()));
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $earlier = $this->loaded($transport);
        $current = $this->loaded($transport);

        [$earlierWaiting] = $earlier->update(new KeyMsg(KeyType::Char, 'p'));
        [, $earlierCmd] = $this->typedHubUrl($earlierWaiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));

        [$currentWaiting] = $current->update(new KeyMsg(KeyType::Char, 'p'));
        [$currentWorking, $currentCmd] = $this->typedHubUrl($currentWaiting, 'hub.example')
            ->update(new KeyMsg(KeyType::Enter));
        self::assertNotNull($currentCmd, 'the current screen sends its own claim');

        // The earlier screen's claim lands on the current screen. Each screen starts its
        // attempts from zero, so the number alone must not let it through.
        $earlierClaim = $this->runCmd($earlierCmd);
        self::assertInstanceOf(HubPairingDoneMsg::class, $earlierClaim);
        [$after, $pollCmd] = $currentWorking->update($earlierClaim);
        self::assertNull($pollCmd, 'a claim from another screen starts no poll here');
        self::assertStringNotContainsString('Claim code', $after->view());
        self::assertStringContainsString('Hub URL:', $after->view(), 'the current wizard stays on its URL prompt');
    }

    public function testABare410IsTheExpiryOnTheFirstPollWithTheLiteralReason(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $transport->json(410, []);                                      // no body: only the HTTP status
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling, $pollCmd] = $working->update($this->runCmd($cmd));

        $expired = $this->runCmd($pollCmd);
        self::assertInstanceOf(HubPairingExpiredMsg::class, $expired);
        self::assertSame('Claim has expired.', $expired->reason);
        self::assertSame(6, $transport->requestCount(), 'a bare 410 is not retried: four statuses, the claim, one poll');

        [, $toastCmd] = $polling->update($expired);
        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertStringContainsString('Claim has expired.', $toast->message);
        self::assertStringNotContainsString('HTTP 410', $toast->message);
    }

    public function testAPollThatKeepsFailingRetriesTenTimesThenToastsTheServersMessage(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        for ($i = 0; $i < 10; $i++) {
            $transport->json(500, ['success' => false, 'message' => 'Hub down']);
        }
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling, $pollCmd] = $working->update($this->runCmd($cmd));

        $expired = $this->settleOnFakeClock($pollCmd);
        self::assertInstanceOf(HubPairingExpiredMsg::class, $expired);
        self::assertSame('Hub down', $expired->reason);
        self::assertSame(15, $transport->requestCount(), 'four statuses, the claim, then all ten polls');

        [$after, $toastCmd] = $polling->update($expired);
        self::assertStringNotContainsString('Claim code', $after->view(), 'the wizard closes');

        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Hub down', $toast->message);
        self::assertStringNotContainsString('Pairing failed.', $toast->message);
    }

    public function testAPollThatNeverSeesPairingTimesOutAfterTenPolls(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        for ($i = 0; $i < 10; $i++) {
            $transport->json(200, ['paired' => false]);
        }
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling, $pollCmd] = $working->update($this->runCmd($cmd));

        $expired = $this->settleOnFakeClock($pollCmd);
        self::assertInstanceOf(HubPairingExpiredMsg::class, $expired);
        self::assertSame('Pairing timed out.', $expired->reason);
        self::assertSame(15, $transport->requestCount(), 'ten pending answers, then the timeout without an eleventh request');

        [$after, $toastCmd] = $polling->update($expired);
        self::assertStringNotContainsString('Claim code', $after->view(), 'the wizard closes');
        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertStringContainsString('Pairing timed out.', $toast->message);
    }

    public function testTheServersPendingEnvelopeIsAPendingPollAndTimesOutAfterTenPolls(): void
    {
        // The real hub answers a claim it has not seen consumed as 200 {success:false}, not paired:false.
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        for ($i = 0; $i < 10; $i++) {
            $transport->json(200, ['success' => false, 'message' => 'Claim is still pending.']);
        }
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling, $pollCmd] = $working->update($this->runCmd($cmd));

        $expired = $this->settleOnFakeClock($pollCmd);
        self::assertInstanceOf(HubPairingExpiredMsg::class, $expired);
        self::assertSame('Pairing timed out.', $expired->reason);
        self::assertSame(15, $transport->requestCount(), 'four statuses, the claim, then ten pending polls');

        [$after, $toastCmd] = $polling->update($expired);
        self::assertStringNotContainsString('Claim code', $after->view(), 'the wizard closes');
        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertStringContainsString('Pairing timed out.', $toast->message);
        self::assertStringNotContainsString('still pending', $toast->message);
    }

    public function testAPollAuthErrorSurfacesASessionExpiryWithoutRetrying(): void
    {
        // A token with no refresh half, so the 401 is not retried through a refresh.
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $transport->json(401, ['error' => 'Unauthorized']);
        $screen = $this->screenWithNoRefresh($transport);
        $screen = $screen->update($this->runCmd($screen->init()))[0];

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [, $pollCmd] = $working->update($this->runCmd($cmd));

        $expired = $this->settleOnFakeClock($pollCmd);
        self::assertInstanceOf(SessionExpiredMsg::class, $expired);
        self::assertSame(6, $transport->requestCount(), 'four statuses, the claim, then one poll with no retry');
    }

    public function testThePollSuccessDoesNotCloseTheLiveWizardBeforeItsMessageIsDispatched(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        $transport->json(200, ['claimCode' => 'ABCD-1234', 'claimId' => 'claim-7', 'expiresIn' => 600]);
        $transport->json(200, ['paired' => true, 'serverId' => 'srv-9']);
        $screen = $this->loaded($transport);

        [$waiting] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        [$working, $cmd] = $this->typedHubUrl($waiting, 'hub.example')->update(new KeyMsg(KeyType::Enter));
        [$polling, $pollCmd] = $working->update($this->runCmd($cmd));

        $paired = $this->settleOnFakeClock($pollCmd);
        self::assertInstanceOf(HubPairingPollSucceededMsg::class, $paired);
        self::assertStringContainsString(
            'Claim code: ABCD-1234',
            $polling->view(),
            'the poll callback must not move the wizard; only the dispatched message does',
        );

        [$after] = $polling->update($paired);
        self::assertStringNotContainsString('Claim code', $after->view(), 'the message closes the wizard');
    }

    /**
     * Run a poll command against the fake clock: each retry sleeps on a one-shot
     * timer, so the chain is advanced by firing those timers in order instead of
     * waiting on the wall clock. The real loop is restored before returning.
     */
    private function settleOnFakeClock(?\Closure $pollCmd): ?Msg
    {
        self::assertNotNull($pollCmd);
        $clock = new FakeClockLoop();
        $previous = Loop::get();
        Loop::set($clock);
        try {
            $result = $pollCmd();
            self::assertInstanceOf(AsyncCmd::class, $result);

            $settled = null;
            $done = false;
            $result->promise->then(static function (mixed $value) use (&$settled, &$done): void {
                $settled = $value;
                $done = true;
            });
            while (!$done) {
                $clock->fireNextOneShot();
            }
        } finally {
            Loop::set($previous);
        }

        return $settled instanceof Msg ? $settled : null;
    }

    // ---- hub unenroll --------------------------------------------------

    public function testHubUnenrollArmsAConfirmThenPerformsOnY(): void
    {
        $transport = $this->statusTransport($this->activePayloads());   // init (paired)
        $transport->json(200, ['success' => true]);                     // unenroll
        foreach ($this->inactivePayloads() as $p) {                     // refetch
            $transport->json(200, $p);
        }
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_HUB);

        [$armed, $armCmd] = $screen->update(new KeyMsg(KeyType::Char, 'u'));
        self::assertNull($armCmd);
        self::assertSame('unenroll', $armed->pendingConfirm());
        self::assertStringContainsString('Unenroll from the hub?', $armed->view());

        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminRemoteActionDoneMsg::class, $done);
        self::assertStringContainsString('Unenrolled', $done->message);
        self::assertStringContainsString('/api/v1/admin/remote/hub/unenroll', $transport->requestAt(4)['url']);
    }

    public function testHubUnenrollIsANoOpWhenUnpaired(): void
    {
        // Inactive payload = unpaired → `u` does nothing (pairing wizard deferred).
        $screen = $this->loaded($this->statusTransport($this->inactivePayloads()));
        self::assertSame(self::PANEL_HUB, $screen->selectedPanel());

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'u'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testAnUnrelatedKeyDuringAConfirmCancels(): void
    {
        $screen = $this->loadedActiveOn(self::PANEL_HUB);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'u'));
        self::assertSame('unenroll', $armed->pendingConfirm());

        // A stray key (not `y`) cancels without acting.
        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertNull($cancelled->pendingConfirm());
        self::assertNull($cmd);
    }

    // ---- failure / auth ------------------------------------------------

    public function testActionFailureToastsTheFriendlyMessageAndLeavesStatusUnchanged(): void
    {
        // LANDMINE: the 409 body uses `message`, NOT `error` — the toast shows the
        // friendly text, not "HTTP 409".
        $transport = $this->statusTransport($this->activePayloads());   // init (connected)
        $transport->json(409, ['success' => false, 'message' => 'Relay not connected.']); // ping
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_RELAY);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        $msg = $this->runCmd($cmd);

        self::assertInstanceOf(AdminRemoteActionFailedMsg::class, $msg);
        self::assertSame('Relay not connected.', $msg->message);

        [$after, $toastCmd] = $busy->update($msg);
        self::assertFalse($after->isBusy());
        self::assertNotNull($after->status());
        self::assertTrue($after->status()->relay->connected, 'the status is unchanged on failure');

        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Relay not connected.', $toast->message);
        self::assertStringNotContainsString('HTTP 409', $toast->message);
    }

    public function testActionAuthErrorSurfacesASessionExpiry(): void
    {
        $transport = $this->statusTransport($this->activePayloads());
        $transport->json(401, ['error' => 'Unauthorized']);   // relay enable, no refresh
        $screen = $this->screenWithNoRefresh($transport);
        $loaded = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminRemoteStatusLoadedMsg::class, $loaded);
        $screen = $screen->update($loaded)[0];
        for ($i = 0; $i < self::PANEL_RELAY; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'd'));
        $msg = $this->runCmd($cmd);

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- refresh / busy / nav / misc -----------------------------------

    public function testRRefetchesAllFourStatuses(): void
    {
        $transport = $this->statusTransport($this->inactivePayloads());
        foreach ($this->activePayloads() as $p) {
            $transport->json(200, $p);
        }
        $screen = $this->loaded($transport);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertTrue($busy->isBusy());
        self::assertFalse($busy->isLoaded());

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminRemoteStatusLoadedMsg::class, $msg);
        self::assertTrue($msg->status->hub->paired);
    }

    public function testActionsAreIgnoredWhileBusy(): void
    {
        $screen = $this->loadedActiveOn(self::PANEL_RELAY);

        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertTrue($busy->isBusy());

        // A second key while busy is a no-op.
        [$same, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertSame($busy, $same);
        self::assertNull($cmd);
    }

    public function testActionKeysAreNoOpsBeforeTheStatusLoads(): void
    {
        $screen = $this->screenWith($this->statusTransport($this->activePayloads()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testTheBusyStateRendersAWorkingNote(): void
    {
        $transport = $this->statusTransport($this->activePayloads());
        $transport->json(200, ['success' => true]);
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_RELAY);

        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 'd'));

        self::assertTrue($busy->isBusy());
        self::assertStringContainsString('Working…', $busy->view());
    }

    public function testEscapeAndQGoBack(): void
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testAnUnhandledKeyIsANoOp(): void
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testANonHandledKeyTypeIsANoOp(): void
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testAnUnhandledMsgIsANoOp(): void
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));

        [$next, $cmd] = $screen->update(new class implements Msg {
        });
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testResizeAdjustsTheFrame(): void
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));

        [$resized] = $screen->update(new WindowSizeMsg(60, 20));
        self::assertNotSame($screen, $resized);
        self::assertStringContainsString('Hub Pairing', $resized->view());
    }

    public function testRefreshIsAllowedDuringBusy(): void
    {
        $transport = $this->statusTransport($this->activePayloads());
        $transport->json(200, ['success' => true]);
        foreach ($this->activePayloads() as $p) {
            $transport->json(200, $p);
        }
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_RELAY);

        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertTrue($busy->isBusy());

        // `r` refreshes even while busy (a harmless re-fetch).
        [$refreshing, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertTrue($refreshing->isBusy());
        self::assertNotNull($cmd);
    }

    public function testCrumbLabelAndWithCrumbsAreImmutable(): void
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));

        self::assertSame('Remote Access', $screen->crumbLabel());

        $withCrumbs = $screen->withCrumbs(['Admin', 'Remote Access']);
        self::assertNotSame($screen, $withCrumbs);
        self::assertSame('Remote Access', $withCrumbs->crumbLabel());
    }

    public function testThemeIsAppliedImmutably(): void
    {
        $screen = $this->loaded($this->statusTransport($this->activePayloads()));

        $themed = $screen->withTheme(Theme::midnight());
        self::assertNotSame($screen, $themed);
        self::assertStringContainsString('Hub Pairing', $themed->view());
    }

    // ---- harness -------------------------------------------------------

    /** A screen loaded with whatever the transport's first 4 GETs yield, panel moved to $panel. */
    private function loadedActiveOnTransport(FakeTransport $transport, int $panel): AdminRemoteAccessScreen
    {
        $screen = $this->loaded($transport);
        for ($i = 0; $i < $panel; $i++) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame($panel, $screen->selectedPanel());

        return $screen;
    }

    /**
     * A Port-Forward-panel screen with the candidates sub-view opened AND its fetch
     * (yielding $payload) resolved — ready to render / scroll.
     */
    private function openedCandidates(array $payload): AdminRemoteAccessScreen
    {
        $transport = $this->statusTransport($this->activePayloads());
        $transport->json(200, $payload);
        $screen = $this->loadedActiveOnTransport($transport, self::PANEL_PORTFORWARD);

        [$opening, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminPortForwardCandidatesLoadedMsg::class, $msg);

        return $opening->update($msg)[0];
    }

    /**
     * @param list<Msg>    $msgs
     * @param class-string $class
     */
    private function firstOf(array $msgs, string $class): ?Msg
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof $class) {
                return $msg;
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

    /**
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

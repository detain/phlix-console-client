<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminLiveTvActionDoneMsg;
use Phlix\Console\Msg\AdminLiveTvActionFailedMsg;
use Phlix\Console\Msg\AdminLiveTvChannelsLoadedMsg;
use Phlix\Console\Msg\AdminLiveTvFailedMsg;
use Phlix\Console\Msg\AdminLiveTvGuideLoadedMsg;
use Phlix\Console\Msg\AdminLiveTvRecordingsLoadedMsg;
use Phlix\Console\Msg\AdminLiveTvSeriesRulesLoadedMsg;
use Phlix\Console\Msg\AdminLiveTvTunersLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AdminLiveTvScreen;
use Phlix\Console\Screen\LiveTvSection;
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

final class AdminLiveTvScreenTest extends TestCase
{
    // ---- server shapes (top-level named keys, per LT1) -----------------

    /** @return array<string, mixed> */
    private function tunersBody(): array
    {
        return ['success' => true, 'tuners' => [
            ['id' => 't-1', 'tuner_id' => 'TU1', 'type' => 'hdhomerun', 'name' => 'Living Room', 'enabled' => 1, 'status' => 'idle'],
            ['id' => 't-2', 'tuner_id' => 'TU2', 'type' => 'iptv', 'name' => 'IPTV', 'enabled' => 0, 'status' => ''],
        ]];
    }

    /** @return array<string, mixed> */
    private function channelsBody(): array
    {
        return ['success' => true, 'channels' => [
            ['id' => 'c-1', 'channel_id' => 'CH1', 'name' => 'BBC One', 'number' => 101, 'callsign' => 'BBC1', 'type' => 'tv', 'enabled' => 1],
            ['id' => 'c-2', 'channel_id' => 'CH2', 'name' => 'Hidden', 'number' => 102, 'visibility' => 'hidden'],
        ]];
    }

    /** @return array<string, mixed> */
    private function guideBody(): array
    {
        return ['success' => true, 'programs' => [
            ['id' => 'p-1', 'program_id' => 'PR1', 'channel_id' => 'channel-very-long-id', 'title' => 'The News', 'start_time' => 1_700_000_000, 'end_time' => 1_700_003_600],
        ]];
    }

    /** @return array<string, mixed> */
    private function recordingsBody(): array
    {
        return ['success' => true, 'recordings' => [
            ['recording_id' => 'r-1', 'channel_id' => 'CH1', 'title' => 'Movie', 'start_time' => 1_700_000_000, 'end_time' => 1_700_003_600, 'status' => 'scheduled', 'storage_size' => 2_097_152],
            ['recording_id' => 'r-2', 'channel_id' => 'CH2', 'title' => 'Show', 'start_time' => 1_700_100_000, 'end_time' => 1_700_103_600, 'status' => 'completed'],
        ]];
    }

    /** @return array<string, mixed> */
    private function rulesBody(): array
    {
        return ['success' => true, 'rules' => [
            ['rule_id' => 'sr-1', 'series_id' => 'S1', 'title' => 'Doctor Who', 'priority' => 5, 'days_ahead' => 14, 'is_active' => 1],
        ]];
    }

    private function screenWith(FakeTransport $transport): AdminLiveTvScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminLiveTvScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /**
     * A screen whose token has NO refresh token, so a 401 surfaces straight as an
     * AuthError (no refresh-retry can consume the queued response).
     */
    private function screenNoRefresh(FakeTransport $transport): AdminLiveTvScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));

        return new AdminLiveTvScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** Drive a no-refresh screen to the Guide section (auth-error tests). */
    private function onGuideNoRefresh(FakeTransport $transport): AdminLiveTvScreen
    {
        $screen = $this->screenNoRefresh($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminLiveTvTunersLoadedMsg::class, $msg);
        [$screen] = $screen->update($msg);
        for ($i = 0; $i < 2; $i++) {
            [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
            $loaded = $this->runCmd($cmd);
            self::assertInstanceOf(Msg::class, $loaded);
            [$screen] = $screen->update($loaded);
        }
        self::assertSame(LiveTvSection::Guide, $screen->activeSection());

        return $screen;
    }

    /** init → tuners loaded, applied. */
    private function loaded(FakeTransport $transport): AdminLiveTvScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminLiveTvTunersLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    // ---- init / Tuners -------------------------------------------------

    public function testInitFetchesTuners(): void
    {
        $transport = (new FakeTransport())->json(200, $this->tunersBody());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminLiveTvTunersLoadedMsg::class, $msg);
        self::assertCount(2, $msg->tuners);
        self::assertSame(1, $transport->requestCount());
        self::assertSame('GET', $transport->lastRequest()['method'] ?? null);
        self::assertStringContainsString('/admin/livetv/tuners', (string) ($transport->lastRequest()['url'] ?? ''));
    }

    public function testLoadingStateBeforeData(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->tunersBody()));

        self::assertFalse($screen->isSectionLoaded(LiveTvSection::Tuners));
        self::assertStringContainsString('Loading', $screen->view());
    }

    public function testRendersTheTunersTable(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));

        self::assertTrue($screen->isSectionLoaded(LiveTvSection::Tuners));
        self::assertCount(2, (array) $screen->tunerList());

        $view = $screen->view();
        self::assertStringContainsString('Living Room', $view);
        self::assertStringContainsString('hdhomerun', $view);
        self::assertStringContainsString('[ Tuners ]', $view, 'the active tab is accented');
        self::assertStringContainsString('Channels', $view, 'the other tabs render');
        self::assertStringContainsString('s scan', $view);
        self::assertStringContainsString('E rename', $view, 'the rename action hint shows');
    }

    public function testEmptyTunersPlaceholder(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, ['success' => true, 'tuners' => []]));

        self::assertStringContainsString('No tuners configured', $screen->view());
    }

    public function testTunerFetchFailureShowsError(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(500, ['message' => 'boom']));
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminLiveTvFailedMsg::class, $msg);

        [$failed] = $screen->update($msg);
        self::assertNotNull($failed->sectionError(LiveTvSection::Tuners));
        self::assertStringContainsString('Press r to retry', $failed->view());
    }

    public function testTunerFetchAuthErrorExpiresSession(): void
    {
        $screen = $this->screenNoRefresh((new FakeTransport())->json(401, ['error' => 'expired']));

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testRRetriesTheActiveSection(): void
    {
        $transport = (new FakeTransport())
            ->json(500, ['message' => 'boom'])
            ->json(200, $this->tunersBody());
        $screen = $this->screenWith($transport);
        $failedMsg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminLiveTvFailedMsg::class, $failedMsg);
        [$errored] = $screen->update($failedMsg);

        [$retrying, $cmd] = $errored->update(new KeyMsg(KeyType::Char, 'r'));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvTunersLoadedMsg::class, $msg);
        [$ok] = $retrying->update($msg);
        self::assertTrue($ok->isSectionLoaded(LiveTvSection::Tuners));
        self::assertSame(2, $transport->requestCount());
    }

    // ---- selection -----------------------------------------------------

    public function testSelectionMovesAndClamps(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));
        self::assertSame(0, $screen->selectedIndex());

        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());

        // Down at the bottom clamps (same instance).
        [$still] = $down->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $still->selectedIndex());
        self::assertSame($down, $still);

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex());
    }

    public function testSelectionMoveOnEmptyIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, ['success' => true, 'tuners' => []]));

        [$next] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame($screen, $next);
    }

    public function testSelectionMovesWithinEachSection(): void
    {
        // Channels: two rows — Down selects the second.
        $channels = $this->onChannels((new FakeTransport())->json(200, $this->tunersBody())->json(200, $this->channelsBody()));
        [$cDown] = $channels->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $cDown->selectedIndex());

        // Recordings: two rows — Down selects the second.
        $recordings = $this->onRecordings((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody()));
        [$rDown] = $recordings->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $rDown->selectedIndex());

        // Guide: a single row — Down clamps (same instance, exercises the Guide
        // activeCount branch).
        $guide = $this->onGuide((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody()));
        [$gDown] = $guide->update(new KeyMsg(KeyType::Down));
        self::assertSame(0, $gDown->selectedIndex());
        self::assertSame($guide, $gDown);
    }

    // ---- tab cycling + lazy fetch + cache ------------------------------

    public function testTabCyclesThroughAllFiveSectionsLazyFetchingEachOnce(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody())
            ->json(200, $this->rulesBody());
        $screen = $this->loaded($transport);
        self::assertSame(1, $transport->requestCount());

        // Tab → Channels (fetch).
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertSame(LiveTvSection::Channels, $screen->activeSection());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvChannelsLoadedMsg::class, $msg);
        [$screen] = $screen->update($msg);
        self::assertSame(2, $transport->requestCount());

        // Tab → Guide (fetch).
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertSame(LiveTvSection::Guide, $screen->activeSection());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvGuideLoadedMsg::class, $msg);
        [$screen] = $screen->update($msg);

        // Tab → Recordings (fetch).
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertSame(LiveTvSection::Recordings, $screen->activeSection());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvRecordingsLoadedMsg::class, $msg);
        [$screen] = $screen->update($msg);

        // Tab → Series Rules (fetch).
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertSame(LiveTvSection::Rules, $screen->activeSection());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvSeriesRulesLoadedMsg::class, $msg);
        [$screen] = $screen->update($msg);
        self::assertSame(5, $transport->requestCount());

        // Tab wraps back to Tuners — already cached, NO refetch.
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
        self::assertSame(LiveTvSection::Tuners, $screen->activeSection());
        self::assertNull($cmd, 'a cached section does not refetch');
        self::assertSame(5, $transport->requestCount());
    }

    public function testShiftTabCyclesBackwards(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->rulesBody());
        $screen = $this->loaded($transport);

        // Shift-Tab from Tuners wraps to Series Rules (fetch).
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab, shift: true));
        self::assertSame(LiveTvSection::Rules, $screen->activeSection());
        self::assertInstanceOf(AdminLiveTvSeriesRulesLoadedMsg::class, $this->runCmd($cmd));
    }

    public function testRightAndLeftAlsoCycleSections(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody());
        $screen = $this->loaded($transport);

        [$right, $cmd] = $screen->update(new KeyMsg(KeyType::Right));
        self::assertSame(LiveTvSection::Channels, $right->activeSection());
        self::assertInstanceOf(AdminLiveTvChannelsLoadedMsg::class, $this->runCmd($cmd));

        // Left wraps Channels → Tuners (cached, no fetch).
        [$left, $leftCmd] = $right->update(new KeyMsg(KeyType::Left));
        self::assertSame(LiveTvSection::Tuners, $left->activeSection());
        self::assertNull($leftCmd);
    }

    // ---- Tuner actions -------------------------------------------------

    public function testScanRefetchesAndToasts(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())                                   // init
            ->json(200, ['success' => true, 'tuners' => [['id' => 't-9', 'name' => 'New', 'type' => 'iptv']]]) // scan
            ->json(200, $this->tunersBody());                                  // refetch
        $screen = $this->loaded($transport);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        self::assertTrue($busy->isBusy());
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);

        $msgs = $this->collectCmd($busy->update($done)[1]);
        self::assertTrue($this->hasSuccessToast($msgs), 'a success toast is emitted');
        self::assertTrue($this->hasLoaded($msgs, AdminLiveTvTunersLoadedMsg::class), 'the tuners refetch');
        self::assertStringContainsString('/tuners/scan', $this->urlOf($transport, 1));
    }

    public function testTunerToggleEnabledPutsAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, ['success' => true, 'tuner' => ['id' => 't-1', 'enabled' => 0]])
            ->json(200, $this->tunersBody());
        $screen = $this->loaded($transport);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        self::assertStringContainsString('disabled', $done->message, 'an enabled tuner toggles to disabled');

        $req = $transport->requestAt(1);
        self::assertSame('PUT', $req['method']);
        self::assertStringContainsString('/tuners/t-1', $req['url']);
        self::assertStringContainsString('"enabled":false', $req['body']);

        self::assertTrue($this->hasLoaded($this->collectCmd($busy->update($done)[1]), AdminLiveTvTunersLoadedMsg::class));
    }

    public function testTunerDeleteArmsThenYConfirms(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, ['success' => true])
            ->json(200, $this->tunersBody());
        $screen = $this->loaded($transport);

        // x arms — NO request fired yet.
        [$armed, $armCmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNull($armCmd);
        self::assertSame('t-1', $armed->pendingDeleteId());
        self::assertStringContainsString("Delete 'Living Room'? (y/n)", $armed->view());
        self::assertSame(1, $transport->requestCount(), 'arming fires no request');

        // y performs the delete.
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        $req = $transport->requestAt(1);
        self::assertSame('DELETE', $req['method']);
        self::assertStringContainsString('/tuners/t-1', $req['url']);
    }

    public function testDeleteNCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cmd);
        self::assertNull($cancelled->pendingDeleteId());
    }

    public function testDeleteEscCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmd);
        self::assertNull($cancelled->pendingDeleteId());
    }

    public function testUnrelatedKeyDuringConfirmIsIgnored(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        [$still, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertNull($cmd);
        self::assertSame('t-1', $still->pendingDeleteId(), 'the confirm stays armed');
    }

    public function testTunerActionOnEmptyListIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, ['success' => true, 'tuners' => []]));

        [$x, $xc] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNull($xc);
        self::assertNull($x->pendingDeleteId());
        [$e, $ec] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertNull($ec);
        self::assertFalse($e->isBusy());
    }

    public function testTunerUnknownActionKeyIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertNull($cmd);
        self::assertSame($screen, $next);
    }

    // ---- Channels ------------------------------------------------------

    private function onChannels(FakeTransport $transport): AdminLiveTvScreen
    {
        $screen = $this->loaded($transport);
        [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvChannelsLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    public function testChannelsRenderAndToggle(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, ['success' => true, 'channel' => ['id' => 'c-1', 'visibility' => 'hidden']])
            ->json(200, $this->channelsBody());
        $screen = $this->onChannels($transport);

        self::assertCount(2, (array) $screen->channelList());
        $view = $screen->view();
        self::assertStringContainsString('BBC One', $view);
        self::assertStringContainsString('101', $view);
        self::assertStringContainsString('e toggle-enabled', $view);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        $req = $transport->requestAt(2);
        self::assertSame('PUT', $req['method']);
        self::assertStringContainsString('/channels/c-1', $req['url']);
        self::assertTrue($this->hasLoaded($this->collectCmd($busy->update($done)[1]), AdminLiveTvChannelsLoadedMsg::class));
    }

    public function testChannelEmptyAndUnknownKeyNoOp(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, ['success' => true, 'channels' => []]);
        $screen = $this->onChannels($transport);

        self::assertStringContainsString('No channels', $screen->view());
        [$e, $ec] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertNull($ec);
        self::assertFalse($e->isBusy());

        // An unknown key on a populated channel list is a no-op too.
        $populated = $this->onChannels((new FakeTransport())->json(200, $this->tunersBody())->json(200, $this->channelsBody()));
        [$z, $zc] = $populated->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertNull($zc);
        self::assertSame($populated, $z);
    }

    // ---- Guide ---------------------------------------------------------

    private function onGuide(FakeTransport $transport): AdminLiveTvScreen
    {
        $screen = $this->loaded($transport);
        for ($i = 0; $i < 2; $i++) {
            [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
            $msg = $this->runCmd($cmd);
            self::assertInstanceOf(Msg::class, $msg);
            [$screen] = $screen->update($msg);
        }
        self::assertSame(LiveTvSection::Guide, $screen->activeSection());

        return $screen;
    }

    public function testGuideRendersAndRefreshes(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, ['success' => true, 'programs' => 42]) // refresh → count
            ->json(200, $this->guideBody());
        $screen = $this->onGuide($transport);

        self::assertCount(1, (array) $screen->guideList());
        $view = $screen->view();
        self::assertStringContainsString('The News', $view);
        self::assertStringContainsString('g refresh-guide', $view);
        // The long channel id is shortened.
        self::assertStringContainsString('…', $view);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'g'));
        self::assertTrue($busy->isBusy());
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        self::assertStringContainsString('Imported 42 programs', $done->message);
        self::assertStringContainsString('/guide/refresh', $this->urlOf($transport, 3));
        self::assertTrue($this->hasLoaded($this->collectCmd($busy->update($done)[1]), AdminLiveTvGuideLoadedMsg::class));
    }

    public function testGuideEmptyPlaceholder(): void
    {
        $screen = $this->onGuide((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, ['success' => true, 'programs' => []]));

        self::assertStringContainsString('No guide data', $screen->view());
    }

    public function testGuideUnknownKeyNoOp(): void
    {
        $screen = $this->onGuide((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertNull($cmd);
        self::assertSame($screen, $next);
    }

    public function testGuideRefreshAuthErrorExpiresSession(): void
    {
        $screen = $this->onGuideNoRefresh((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(401, ['error' => 'expired']));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'g'));
        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    public function testGuideRefreshFailureToasts(): void
    {
        $screen = $this->onGuide((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(500, ['message' => 'epg down']));

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'g'));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionFailedMsg::class, $failed);
        self::assertSame('epg down', $failed->message);

        [$idle, $toastCmd] = $busy->update($failed);
        self::assertFalse($idle->isBusy(), 'a failed action leaves busy');
        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    // ---- Recordings ----------------------------------------------------

    private function onRecordings(FakeTransport $transport): AdminLiveTvScreen
    {
        $screen = $this->loaded($transport);
        for ($i = 0; $i < 3; $i++) {
            [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
            $msg = $this->runCmd($cmd);
            self::assertInstanceOf(Msg::class, $msg);
            [$screen] = $screen->update($msg);
        }
        self::assertSame(LiveTvSection::Recordings, $screen->activeSection());

        return $screen;
    }

    public function testRecordingsRenderWithHumanBytesAndDelete(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody())
            ->json(200, ['success' => true])      // delete
            ->json(200, $this->recordingsBody());  // refetch
        $screen = $this->onRecordings($transport);

        self::assertCount(2, (array) $screen->recordingList());
        $view = $screen->view();
        self::assertStringContainsString('Movie', $view);
        self::assertStringContainsString('Scheduled', $view);
        self::assertStringContainsString('2.0 MiB', $view, 'storage size is humanized');
        self::assertStringContainsString('x delete', $view);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame('r-1', $armed->pendingDeleteId());

        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        $req = $transport->requestAt(4);
        self::assertSame('DELETE', $req['method']);
        self::assertStringContainsString('/recordings/r-1', $req['url']);
        self::assertTrue($this->hasLoaded($this->collectCmd($busy->update($done)[1]), AdminLiveTvRecordingsLoadedMsg::class));
    }

    public function testRecordingSizesHumanizeAcrossUnits(): void
    {
        $body = ['success' => true, 'recordings' => [
            ['recording_id' => 'r-z', 'channel_id' => 'CH', 'title' => 'Zero', 'start_time' => 1_700_000_000, 'end_time' => 1, 'status' => 'failed', 'storage_size' => 0],
            ['recording_id' => 'r-b', 'channel_id' => 'CH', 'title' => 'Bytes', 'start_time' => 1_700_000_000, 'end_time' => 1, 'status' => 'recording', 'storage_size' => 512],
        ]];
        $screen = $this->onRecordings((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $body));

        $view = $screen->view();
        self::assertStringContainsString('0 B', $view, 'a zero-byte recording clamps to 0 B');
        self::assertStringContainsString('512 B', $view, 'a sub-KiB recording stays in bytes');
    }

    public function testBusyStatusLineRendersWorking(): void
    {
        // The busy view is the synchronous state right after pressing `s`, before
        // the scan promise resolves — no need to run the command.
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));

        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        self::assertTrue($busy->isBusy());
        self::assertStringContainsString('Working…', $busy->view());
    }

    public function testRecordingUpcomingToggleRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody())                                   // all
            ->json(200, ['success' => true, 'recordings' => []]);                   // upcoming (empty)
        $screen = $this->onRecordings($transport);
        self::assertFalse($screen->isUpcomingOnly());

        [$toggled, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'u'));
        self::assertTrue($toggled->isUpcomingOnly());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvRecordingsLoadedMsg::class, $msg);
        [$upcoming] = $toggled->update($msg);
        self::assertStringContainsString('No upcoming recordings', $upcoming->view());
        self::assertStringContainsString('/recordings/upcoming', $this->urlOf($transport, 4));
    }

    public function testRecordingsEmptyAndUnknownKey(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, ['success' => true, 'recordings' => []]);
        $screen = $this->onRecordings($transport);

        self::assertStringContainsString('No recordings', $screen->view());
        [$x, $xc] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNull($xc);
        self::assertNull($x->pendingDeleteId());

        $populated = $this->onRecordings((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody()));
        [$z, $zc] = $populated->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertNull($zc);
        self::assertSame($populated, $z);
    }

    // ---- Series Rules --------------------------------------------------

    private function onRules(FakeTransport $transport): AdminLiveTvScreen
    {
        $screen = $this->loaded($transport);
        for ($i = 0; $i < 4; $i++) {
            [$screen, $cmd] = $screen->update(new KeyMsg(KeyType::Tab));
            $msg = $this->runCmd($cmd);
            self::assertInstanceOf(Msg::class, $msg);
            [$screen] = $screen->update($msg);
        }
        self::assertSame(LiveTvSection::Rules, $screen->activeSection());

        return $screen;
    }

    public function testSeriesRulesRenderAndDelete(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody())
            ->json(200, $this->rulesBody())
            ->json(200, ['success' => true])      // delete
            ->json(200, $this->rulesBody());       // refetch
        $screen = $this->onRules($transport);

        self::assertCount(1, (array) $screen->ruleList());
        $view = $screen->view();
        self::assertStringContainsString('Doctor Who', $view);
        self::assertStringContainsString('[ Series Rules ]', $view);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame('sr-1', $armed->pendingDeleteId());
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        $req = $transport->requestAt(5);
        self::assertSame('DELETE', $req['method']);
        self::assertStringContainsString('/series-rules/sr-1', $req['url']);
        self::assertTrue($this->hasLoaded($this->collectCmd($busy->update($done)[1]), AdminLiveTvSeriesRulesLoadedMsg::class));
    }

    public function testSeriesRulesEmptyAndUnknownKey(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody())
            ->json(200, ['success' => true, 'rules' => []]);
        $screen = $this->onRules($transport);

        self::assertStringContainsString('No series rules', $screen->view());
        [$x, $xc] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNull($xc);
        self::assertNull($x->pendingDeleteId());

        $populated = $this->onRules((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody())
            ->json(200, $this->rulesBody()));
        [$z, $zc] = $populated->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertNull($zc);
        self::assertSame($populated, $z);
    }

    // ---- Guide: record / record-series (P8C.7) -------------------------

    /** @return array<string, mixed> */
    private function guideSeriesBody(): array
    {
        return ['success' => true, 'programs' => [
            ['id' => 'p-1', 'program_id' => 'PR1', 'channel_id' => 'CH1', 'title' => 'Doctor Who', 'start_time' => 1_700_000_000, 'end_time' => 1_700_003_600, 'series_id' => 'SH1'],
        ]];
    }

    public function testGuideRecordArmsThenYCreatesAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideSeriesBody())
            ->json(200, ['success' => true, 'recording' => ['recording_id' => 'rec-9']]) // create
            ->json(200, $this->guideSeriesBody());                                         // refetch
        $screen = $this->onGuide($transport);

        $view = $screen->view();
        self::assertStringContainsString('R record', $view);
        self::assertStringContainsString('S record-series', $view);

        // R arms — NO request fired yet.
        [$armed, $armCmd] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        self::assertNull($armCmd);
        self::assertNotNull($armed->pendingCreate());
        self::assertStringContainsString("Record 'Doctor Who'? (y/n)", $armed->view());
        self::assertSame(3, $transport->requestCount(), 'arming fires no request');

        // y performs the create.
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        self::assertSame('Recording scheduled', $done->message);
        $req = $transport->requestAt(3);
        self::assertSame('POST', $req['method']);
        self::assertStringContainsString('/recordings', $req['url']);
        $body = json_decode($req['body'], true);
        self::assertSame('CH1', $body['channel_id']);
        self::assertSame(1_700_000_000, $body['start_time']);
        self::assertSame('Doctor Who', $body['title']);
        self::assertSame('PR1', $body['program_id']);
        self::assertTrue($this->hasLoaded($this->collectCmd($busy->update($done)[1]), AdminLiveTvGuideLoadedMsg::class));
    }

    public function testGuideRecordNCancels(): void
    {
        $screen = $this->onGuide((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideSeriesBody()));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        self::assertNotNull($armed->pendingCreate());
        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cmd);
        self::assertNull($cancelled->pendingCreate());
    }

    public function testGuideRecordEscCancelsAndUnrelatedKeyKeepsArmed(): void
    {
        $screen = $this->onGuide((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideSeriesBody()));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        [$still, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertNull($cmd);
        self::assertNotNull($still->pendingCreate(), 'an unrelated key keeps the confirm armed');

        [$cancelled, $escCmd] = $armed->update(new KeyMsg(KeyType::Escape));
        self::assertNull($escCmd);
        self::assertNull($cancelled->pendingCreate());
    }

    public function testGuideRecordSeriesCreatesARule(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideSeriesBody())
            ->json(200, ['success' => true, 'rule' => ['rule_id' => 'sr-9']]) // create series
            ->json(200, $this->guideSeriesBody());
        $screen = $this->onGuide($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'S'));
        self::assertNotNull($armed->pendingCreate());
        self::assertStringContainsString('whole series', $armed->view());

        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        self::assertSame('Series rule created', $done->message);
        $req = $transport->requestAt(3);
        self::assertSame('POST', $req['method']);
        self::assertStringContainsString('/series-rules', $req['url']);
        $body = json_decode($req['body'], true);
        self::assertSame('SH1', $body['series_id']);
        self::assertSame('CH1', $body['channel_id']);
    }

    public function testGuideRecordSeriesIsDisabledWhenTheProgramHasNoSeriesId(): void
    {
        // The default guideBody() program carries NO series_id.
        $screen = $this->onGuide((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody()));

        self::assertStringContainsString('not a series', $screen->view(), 'the S-unavailable note shows');

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'S'));
        self::assertNull($cmd, 'S fires nothing without a series id');
        self::assertNull($next->pendingCreate());
    }

    public function testGuideRecordOnEmptyListIsANoOp(): void
    {
        $screen = $this->onGuide((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, ['success' => true, 'programs' => []]));

        [$r, $rc] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        self::assertNull($rc);
        self::assertNull($r->pendingCreate());
        [$s, $sc] = $screen->update(new KeyMsg(KeyType::Char, 'S'));
        self::assertNull($sc);
        self::assertNull($s->pendingCreate());
    }

    public function testGuideRecordCreateFailureToasts(): void
    {
        $screen = $this->onGuide((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideSeriesBody())
            ->json(500, ['success' => false, 'message' => 'tuner busy']));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionFailedMsg::class, $failed);
        self::assertSame('tuner busy', $failed->message);

        [$idle, $toastCmd] = $busy->update($failed);
        self::assertFalse($idle->isBusy());
        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testGuideRecordCreateAuthErrorExpiresSession(): void
    {
        $screen = $this->onGuideNoRefresh((new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideSeriesBody())
            ->json(401, ['error' => 'expired']));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        [, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    // ---- Tuner / Channel rename (P8C.7) --------------------------------

    public function testTunerRenameOpensFormThenSubmitsPut(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, ['success' => true, 'tuner' => ['id' => 't-1', 'name' => 'Den']]) // rename PUT
            ->json(200, $this->tunersBody());                                              // refetch
        $screen = $this->loaded($transport);

        [$editing, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        self::assertNull($cmd);
        self::assertTrue($editing->isEditing());
        self::assertSame('tuner', $editing->editKind());
        self::assertStringContainsString('Rename Tuner', $editing->view());

        // Replace the pre-filled name and submit.
        $typed = $this->typeInto($this->clearInput($editing), 'Den');
        [$busy, $submitCmd] = $this->submit($typed);
        self::assertTrue($busy->isBusy());
        $done = $this->runCmd($submitCmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        self::assertSame('Tuner renamed', $done->message);

        $req = $transport->requestAt(1);
        self::assertSame('PUT', $req['method']);
        self::assertStringContainsString('/tuners/t-1', $req['url']);
        self::assertStringContainsString('"name":"Den"', $req['body']);
        self::assertTrue($this->hasLoaded($this->collectCmd($busy->update($done)[1]), AdminLiveTvTunersLoadedMsg::class));
    }

    public function testTunerRenameEscCancelsTheForm(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        self::assertTrue($editing->isEditing());

        [$cancelled, $cmd] = $editing->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmd);
        self::assertFalse($cancelled->isEditing());
    }

    public function testTunerRenameRejectsABlankName(): void
    {
        $transport = (new FakeTransport())->json(200, $this->tunersBody());
        $screen = $this->loaded($transport);

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        // Clear the pre-filled name, then submit — the blank is rejected with no PUT.
        $cleared = $this->clearInput($editing);
        [$still, $cmd] = $this->submit($cleared);
        self::assertTrue($still->isEditing(), 'a blank name keeps the form open');
        self::assertSame(1, $transport->requestCount(), 'no rename request is fired');
        $toast = $this->runCmd($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testChannelRenameSubmitsPut(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, ['success' => true, 'channel' => ['id' => 'c-1', 'name' => 'BBC Two']])
            ->json(200, $this->channelsBody());
        $screen = $this->onChannels($transport);

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        self::assertSame('channel', $editing->editKind());
        self::assertStringContainsString('Rename Channel', $editing->view());

        $typed = $this->typeInto($this->clearInput($editing), 'BBC Two');
        [$busy, $cmd] = $this->submit($typed);
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        self::assertSame('Channel renamed', $done->message);
        $req = $transport->requestAt(2);
        self::assertSame('PUT', $req['method']);
        self::assertStringContainsString('/channels/c-1', $req['url']);
        self::assertStringContainsString('"name":"BBC Two"', $req['body']);
    }

    public function testRenameOnEmptyListIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, ['success' => true, 'tuners' => []]));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        self::assertNull($cmd);
        self::assertFalse($next->isEditing());
    }

    // ---- Series-rule edit (P8C.7) --------------------------------------

    public function testRuleEditOpensPrefilledFormAndPutsChangedFields(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody())
            ->json(200, $this->rulesBody())
            ->json(200, ['success' => true, 'rule' => ['rule_id' => 'sr-1']]) // PUT
            ->json(200, $this->rulesBody());                                  // refetch
        $screen = $this->onRules($transport);

        [$editing, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        self::assertNull($cmd);
        self::assertTrue($editing->isEditing());
        self::assertSame('rule', $editing->editKind());
        $view = $editing->view();
        self::assertStringContainsString('Edit Series Rule', $view);
        self::assertStringContainsString('Doctor Who', $view, 'the title is pre-filled');

        // Set a blank-by-default optional (pre-padding) to a valid digit value — this
        // exercises the on-keystroke field validator — then submit.
        $edited = $this->setField($editing, 'pre_pad', '30');
        [$busy, $submitCmd] = $this->submit($edited);
        $done = $this->runCmd($submitCmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        self::assertSame('Series rule updated', $done->message);
        $req = $transport->requestAt(5);
        self::assertSame('PUT', $req['method']);
        self::assertStringContainsString('/series-rules/sr-1', $req['url']);
        $body = json_decode($req['body'], true);
        self::assertSame('Doctor Who', $body['title']);
        self::assertSame(5, $body['priority']);
        self::assertSame(30, $body['pre_padding_seconds']);
        self::assertSame(14, $body['days_ahead']);
        self::assertTrue($this->hasLoaded($this->collectCmd($busy->update($done)[1]), AdminLiveTvSeriesRulesLoadedMsg::class));
    }

    public function testRuleEditRejectsANegativeOrNonIntPriorityWithNoRequest(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody())
            ->json(200, $this->rulesBody());
        $screen = $this->onRules($transport);

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        // Set priority to a non-digit value (-1 has a '-' → not ctype_digit), then run
        // ONE form-level submit (Enter through to the last field). candy-forms submits
        // without enforcing the per-field predicates, so the screen's guard fires.
        $bad = $this->setField($editing, 'priority', '-1');
        [$still, $cmd] = $this->submitOnce($bad);
        self::assertTrue($still->isEditing(), 'an invalid numeric keeps the form open');
        self::assertSame(5, $transport->requestCount(), 'no PUT is fired');
        // The rejected submit emits an error toast, not a PUT.
        $toast = $this->runCmd($cmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);

        // REGRESSION: the re-opened form must NOT be wedged — a follow-up keystroke
        // is still handled (the submitted-form short-circuit would swallow it).
        [$typed, $typeCmd] = $still->update(new KeyMsg(KeyType::Char, 'X'));
        self::assertNull($typeCmd);
        self::assertTrue($typed->isEditing());
        self::assertNotSame($still, $typed, 'the keystroke mutates the form (not wedged)');

        // And Esc still cancels the re-opened form.
        [$cancelled, $escCmd] = $still->update(new KeyMsg(KeyType::Escape));
        self::assertNull($escCmd);
        self::assertFalse($cancelled->isEditing(), 'Esc cancels the re-opened form');

        // Correcting the value and submitting once now PUTs (the form is usable).
        $fixed = $this->setField($still, 'priority', '8');
        [$busy, $okCmd] = $this->submitOnce($fixed);
        self::assertTrue($busy->isBusy());
        $done = $this->runCmd($okCmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        $req = $transport->requestAt(5);
        self::assertSame('PUT', $req['method']);
        $body = json_decode($req['body'], true);
        self::assertSame(8, $body['priority'], 'the corrected value is sent');
    }

    public function testRuleEditAcceptsAStraySpaceNumber(): void
    {
        // A " 5 " priority passes the trim-based submit guard AND is sent (not
        // silently dropped) because optionalInt() trims too.
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody())
            ->json(200, $this->rulesBody())
            ->json(200, ['success' => true, 'rule' => ['rule_id' => 'sr-1']])
            ->json(200, $this->rulesBody());
        $screen = $this->onRules($transport);

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        $spaced = $this->setField($editing, 'priority', ' 5 ');
        [$busy, $cmd] = $this->submitOnce($spaced);
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionDoneMsg::class, $done);
        $body = json_decode($transport->requestAt(5)['body'], true);
        self::assertSame(5, $body['priority'], 'a stray-space number is accepted and sent');
    }

    public function testRuleEditOnEmptyListIsANoOp(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->channelsBody())
            ->json(200, $this->guideBody())
            ->json(200, $this->recordingsBody())
            ->json(200, ['success' => true, 'rules' => []]);
        $screen = $this->onRules($transport);

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        self::assertNull($cmd);
        self::assertFalse($next->isEditing());
    }

    // ---- action failure + busy + section error -------------------------

    public function testActionFailureToastsAndLeavesListUnchanged(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(500, ['message' => 'tuner busy']); // scan fails
        $screen = $this->loaded($transport);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLiveTvActionFailedMsg::class, $failed);
        self::assertSame('tuner busy', $failed->message);

        [$idle, $toastCmd] = $busy->update($failed);
        self::assertFalse($idle->isBusy());
        self::assertCount(2, (array) $idle->tunerList(), 'the list is unchanged');
        $toast = $this->runCmd($toastCmd);
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
    }

    public function testActionAuthErrorExpiresSession(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(401, ['error' => 'expired']);
        $screen = $this->screenNoRefresh($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminLiveTvTunersLoadedMsg::class, $msg);
        [$screen] = $screen->update($msg);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    public function testBusyGuardIgnoresActionKeys(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, ['success' => true, 'tuners' => []]); // pending scan
        $screen = $this->loaded($transport);

        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        self::assertTrue($busy->isBusy());

        // Another action key while busy is ignored.
        [$still, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNull($cmd);
        self::assertSame($busy, $still);
    }

    public function testRefreshWorksWhileBusy(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->tunersBody())
            ->json(200, $this->tunersBody());
        $screen = $this->loaded($transport);

        [$refreshing, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertInstanceOf(\Closure::class, $cmd);
        self::assertFalse($refreshing->isSectionLoaded(LiveTvSection::Tuners), 'the cache is dropped while refetching');
        self::assertStringContainsString('Loading', $refreshing->view());
    }

    // ---- nav / misc ----------------------------------------------------

    public function testEscapeAndQGoBack(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testResizeReflows(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));

        [$resized, $cmd] = $screen->update(new WindowSizeMsg(60, 20));
        self::assertNull($cmd);
        self::assertStringContainsString('Tuners', $resized->view());
    }

    public function testUnhandledKeyAndMessageAreNoOps(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));

        [$key, $keyCmd] = $screen->update(new KeyMsg(KeyType::Insert));
        self::assertNull($keyCmd);
        self::assertSame($screen, $key);

        [$msg, $msgCmd] = $screen->update(new class implements Msg {});
        self::assertNull($msgCmd);
        self::assertSame($screen, $msg);
    }

    public function testCrumbLabelAndWithCrumbsAreImmutable(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));
        self::assertSame('Live TV', $screen->crumbLabel());

        $crumbed = $screen->withCrumbs(['Admin', 'Live TV']);
        self::assertNotSame($screen, $crumbed);
        self::assertStringContainsString('Live TV', $crumbed->view());
    }

    public function testWithThemeIsImmutableAndRenders(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->tunersBody()));
        $themed = $screen->withTheme(Theme::midnight());

        self::assertNotSame($screen, $themed);
        self::assertNotSame('', $themed->view());
    }

    // ---- helpers -------------------------------------------------------

    private function urlOf(FakeTransport $transport, int $index): string
    {
        return (string) ($transport->requestAt($index)['url'] ?? '');
    }

    /** Type each char of $text into the screen's open form. */
    private function typeInto(AdminLiveTvScreen $screen, string $text): AdminLiveTvScreen
    {
        foreach (mb_str_split($text) as $ch) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $screen;
    }

    /** Backspace-clear the current field of the screen's open form. */
    private function clearInput(AdminLiveTvScreen $screen): AdminLiveTvScreen
    {
        for ($i = 0; $i < 24; ++$i) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Backspace));
        }

        return $screen;
    }

    /**
     * Navigate to the named rule-form field (advancing with Enter), clear it, and
     * type $value. The rule form's field order is title, priority, pre_pad,
     * post_pad, max, days.
     */
    private function setField(AdminLiveTvScreen $screen, string $field, string $value): AdminLiveTvScreen
    {
        $order = ['title', 'priority', 'pre_pad', 'post_pad', 'max', 'days'];
        $steps = (int) array_search($field, $order, true);
        for ($i = 0; $i < $steps; ++$i) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Enter));
        }

        return $this->typeInto($this->clearInput($screen), $value);
    }

    /**
     * Submit the screen's open form by pressing Enter through every remaining
     * field (Enter on the last field submits). Returns the resulting state + cmd.
     *
     * @return array{AdminLiveTvScreen, ?\Closure}
     */
    private function submit(AdminLiveTvScreen $screen): array
    {
        $result = [$screen, null];
        for ($i = 0; $i < 8; ++$i) {
            $result = $screen->update(new KeyMsg(KeyType::Enter));
            $screen = $result[0];
            if (!$screen->isEditing()) {
                break;
            }
        }

        return $result;
    }

    /**
     * Run EXACTLY one form-level submit from a known current rule-field: press Enter
     * once per remaining field (the last Enter, on the `days` field, submits).
     * Unlike {@see submit()} this fires a SINGLE submit, so a re-opened (invalid)
     * form is NOT auto-resubmitted — exposing any wedge. candy-forms returns a
     * cursor-blink tick on each advance; the submit result is the LAST press.
     *
     * @return array{AdminLiveTvScreen, ?\Closure}
     */
    private function submitOnce(AdminLiveTvScreen $screen, string $fromField = 'priority'): array
    {
        $order = ['title', 'priority', 'pre_pad', 'post_pad', 'max', 'days'];
        $steps = count($order) - (int) array_search($fromField, $order, true); // advances to reach + submit on days
        $result = [$screen, null];
        for ($i = 0; $i < $steps; ++$i) {
            $result = $screen->update(new KeyMsg(KeyType::Enter));
            $screen = $result[0];
        }

        return $result;
    }

    /** @param list<Msg> $msgs */
    private function hasSuccessToast(array $msgs): bool
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof ShowToastMsg && $msg->type === ToastType::Success) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Msg> $msgs
     * @param class-string $class
     */
    private function hasLoaded(array $msgs, string $class): bool
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof $class) {
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

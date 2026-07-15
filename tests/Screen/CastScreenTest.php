<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Cast\CastBackend;
use Phlix\Console\Api\Cast\CastClient;
use Phlix\Console\Api\Dto\Cast\CastDevice;
use Phlix\Console\Api\Dto\Cast\CastStatus;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\CastActionDoneMsg;
use Phlix\Console\Msg\CastActionFailedMsg;
use Phlix\Console\Msg\CastDevicesFailedMsg;
use Phlix\Console\Msg\CastDevicesLoadedMsg;
use Phlix\Console\Msg\CastFailedMsg;
use Phlix\Console\Msg\CastStartedMsg;
use Phlix\Console\Msg\CastStatusLoadedMsg;
use Phlix\Console\Msg\CastStatusTickMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\CastScreen;
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

final class CastScreenTest extends TestCase
{
    private const BASE = 'https://srv.example';

    private function item(): MediaItem
    {
        return MediaItem::fromArray([
            'id' => 'm-7',
            'name' => 'The Matrix',
            'type' => 'movie',
            'poster_url' => 'https://srv.example/p/m-7.jpg',
            'runtime' => 136,
            'stream_url' => 'https://srv.example/media/m-7/stream?sig=x',
        ]);
    }

    private function screenWith(FakeTransport $transport): CastScreen
    {
        $api = new ApiClient(self::BASE, $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new CastScreen(new CastClient($api), $this->item(), self::BASE, cols: 120, rows: 40);
    }

    /** A screen whose token has NO refresh token, so a 401 surfaces an AuthError (no refresh-retry). */
    private function screenNoRefresh(FakeTransport $transport): CastScreen
    {
        $api = new ApiClient(self::BASE, $transport);
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));

        return new CastScreen(new CastClient($api), $this->item(), self::BASE, cols: 120, rows: 40);
    }

    /** All four discovery legs scripted with one device each (cc, roku, airplay, dlna order). */
    private function fullDiscovery(): FakeTransport
    {
        return (new FakeTransport())
            ->json(200, ['devices' => [['device_id' => 'cc-1', 'name' => 'Living Room TV', 'model' => 'Chromecast Ultra']]])
            ->json(200, ['devices' => [['device_id' => 'roku-1', 'name' => 'Bedroom Roku', 'model' => 'Roku Ultra']]])
            ->json(200, ['devices' => [['device_id' => 'ap-1', 'name' => 'Apple TV', 'supports_video' => true]]])
            ->json(200, ['renderers' => [['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV', 'manufacturer' => 'Samsung']]]);
    }

    /** Drive init → the loaded Msg → the picker. */
    private function picker(FakeTransport $transport): CastScreen
    {
        return $this->pickerOn($this->screenWith($transport));
    }

    private function pickerOn(CastScreen $screen): CastScreen
    {
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(CastDevicesLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    /** A device of each backend, for direct fabrication. */
    private function device(CastBackend $backend): CastDevice
    {
        return new CastDevice($backend, 'id-1', 'Test Device', 'Model X', 'detail', null);
    }

    // ---- discovery / init ----------------------------------------------

    public function testInitFansOutDiscovery(): void
    {
        $transport = $this->fullDiscovery();
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(CastDevicesLoadedMsg::class, $msg);
        self::assertCount(4, $msg->devices);
        self::assertSame(4, $transport->requestCount());
    }

    public function testDiscoveringStateBeforeDevices(): void
    {
        $screen = $this->screenWith($this->fullDiscovery());

        self::assertSame('discovering', $screen->mode());
        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Searching for devices', $screen->view());
    }

    public function testCrumbAndHint(): void
    {
        $view = $this->screenWith($this->fullDiscovery())->view();

        self::assertStringContainsString('Cast', $view);
    }

    // ---- picker --------------------------------------------------------

    public function testPickerRendersTheDeviceTableWithEachBackend(): void
    {
        $screen = $this->picker($this->fullDiscovery());

        self::assertSame('picker', $screen->mode());
        self::assertTrue($screen->isLoaded());
        self::assertCount(4, $screen->deviceList());

        $view = $screen->view();
        self::assertStringContainsString('Living Room TV', $view);
        self::assertStringContainsString('Chromecast', $view);
        self::assertStringContainsString('Roku', $view);
        self::assertStringContainsString('AirPlay', $view);
        self::assertStringContainsString('DLNA', $view);
        self::assertStringContainsString('The Matrix', $view);
        self::assertStringContainsString('cast', $view);
    }

    public function testSelectionMovesAndClamps(): void
    {
        $screen = $this->picker($this->fullDiscovery());
        self::assertSame(0, $screen->selectedIndex());

        [$up] = $screen->update(new KeyMsg(KeyType::Up));
        self::assertSame($screen, $up, 'up at the top is a clamped no-op');

        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->selectedIndex());

        $bottom = $down;
        for ($i = 0; $i < 10; $i++) {
            [$bottom] = $bottom->update(new KeyMsg(KeyType::Down));
        }
        self::assertSame(3, $bottom->selectedIndex(), 'clamped at the last device');
    }

    public function testEmptyDevicesShowsThePlaceholder(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['devices' => []])
            ->json(200, ['devices' => []])
            ->json(200, ['devices' => []])
            ->json(200, ['renderers' => []]);

        $screen = $this->picker($transport);

        self::assertSame([], $screen->deviceList());
        self::assertStringContainsString('No cast devices found', $screen->view());
        self::assertStringContainsString('rescan', $screen->view());
    }

    public function testSelectionInAnEmptyPickerIsANoOp(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['devices' => []])
            ->json(200, ['devices' => []])
            ->json(200, ['devices' => []])
            ->json(200, ['renderers' => []]);
        $screen = $this->picker($transport);

        [$down] = $screen->update(new KeyMsg(KeyType::Down));
        [$up] = $screen->update(new KeyMsg(KeyType::Up));

        self::assertSame($screen, $down);
        self::assertSame($screen, $up);
    }

    public function testUnnamedDeviceRendersAPlaceholderName(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['devices' => [['device_id' => 'cc-1']]])
            ->json(200, ['devices' => []])
            ->json(200, ['devices' => []])
            ->json(200, ['renderers' => []]);

        $screen = $this->picker($transport);

        self::assertStringContainsString('(unnamed)', $screen->view());
    }

    public function testRescanReDiscovers(): void
    {
        $screen = $this->picker($this->fullDiscovery());

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertSame('discovering', $next->mode());
        self::assertSame([], $next->deviceList());
        self::assertInstanceOf(\Closure::class, $cmd);
    }

    public function testDiscoveryFailureShowsErrorAndRetry(): void
    {
        $screen = $this->screenWith(new FakeTransport());
        [$failed] = $screen->update(new CastDevicesFailedMsg('Could not search for cast devices.'));

        self::assertSame('Could not search for cast devices.', $failed->error());
        self::assertStringContainsString('rescan', $failed->view());

        [$rescanning, $cmd] = $failed->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertSame('discovering', $rescanning->mode());
        self::assertInstanceOf(\Closure::class, $cmd);
    }

    public function testEscFromPickerNavigatesBack(): void
    {
        $screen = $this->picker($this->fullDiscovery());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    public function testQFromPickerNavigatesBack(): void
    {
        $screen = $this->picker($this->fullDiscovery());

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertInstanceOf(NavigateBackMsg::class, $cmd?->__invoke());
    }

    public function testUnhandledPickerKeyIsANoOp(): void
    {
        $screen = $this->picker($this->fullDiscovery());

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- cast send -----------------------------------------------------

    public function testEnterCastsTheSelectedDeviceAndEntersTransport(): void
    {
        // A Chromecast send → {session_id, state}.
        $transport = $this->fullDiscovery()->json(200, ['session_id' => 'sess-1', 'state' => 'PLAYING']);
        $screen = $this->picker($transport);

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertSame($screen, $same, 'the screen stays in the picker until the send resolves');

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(CastStartedMsg::class, $msg);
        self::assertSame('cc-1', $msg->device->id);

        // The cast request shape: POST to the Chromecast cast endpoint with the
        // absolute stream URL, title, poster, and duration.
        $req = $transport->requests[4];
        self::assertSame('POST', $req['method']);
        self::assertStringContainsString('/api/v1/cast/devices/cc-1/cast', $req['url']);
        $body = json_decode($req['body'], true);
        self::assertSame('https://srv.example/media/m-7/stream?sig=x', $body['media_url']);
        self::assertSame('The Matrix', $body['title']);
        self::assertSame(136, $body['duration']);

        [$transport2, $tick] = $screen->update($msg);
        self::assertSame('transport', $transport2->mode());
        self::assertSame('cc-1', $transport2->activeDevice()?->id);
        self::assertInstanceOf(\Closure::class, $tick, 'the status poll is armed on entering transport');
    }

    /**
     * Empty string posterUrl must not crash the cast — it must be treated as
     * "no poster" and pass null to castTo, avoiding "URL scheme unknown" errors
     * when an empty string is passed to URL resolution.
     */
    public function testEmptyStringPosterUrlDoesNotCrashAndTreatsAsNoPoster(): void
    {
        // Create an item with empty string posterUrl.
        $itemEmptyPoster = MediaItem::fromArray([
            'id' => 'm-8',
            'name' => 'No Poster Movie',
            'type' => 'movie',
            'poster_url' => '', // empty string — the bug case
            'runtime' => 90,
            'stream_url' => 'https://srv.example/media/m-8/stream?sig=x',
        ]);

        $api = new ApiClient(self::BASE, (new FakeTransport())
            ->json(200, ['devices' => [['device_id' => 'cc-1', 'name' => 'Living Room TV', 'model' => 'Chromecast Ultra']]])
            ->json(200, ['session_id' => 'sess-2', 'state' => 'PLAYING']));
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        $screen = new CastScreen(new CastClient($api), $itemEmptyPoster, self::BASE, cols: 120, rows: 40);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(CastDevicesLoadedMsg::class, $msg);
        $picker = $screen->update($msg)[0];

        // Enter should still work and cast with null poster.
        [, $cmd] = $picker->update(new KeyMsg(KeyType::Enter));
        $castMsg = $this->runCmd($cmd);
        self::assertInstanceOf(CastStartedMsg::class, $castMsg, 'cast with empty string posterUrl does not crash');
    }

    public function testEnterWithNoDevicesIsANoOp(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['devices' => []])
            ->json(200, ['devices' => []])
            ->json(200, ['devices' => []])
            ->json(200, ['renderers' => []]);
        $screen = $this->picker($transport);

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testCastFailureToastsAndStaysInThePicker(): void
    {
        $screen = $this->picker($this->fullDiscovery());

        [$next, $cmd] = $screen->update(new CastFailedMsg('device offline'));

        self::assertSame('picker', $next->mode());
        $toast = $cmd?->__invoke();
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertSame('device offline', $toast->message);
    }

    public function testCastSendResolvesRelativeStreamAndPosterUrls(): void
    {
        // A relative stream/poster (not yet seen on prod, but the resolver handles
        // it) is made absolute against the server base before the send.
        $item = MediaItem::fromArray([
            'id' => 'm-7',
            'name' => 'The Matrix',
            'type' => 'movie',
            'poster_url' => '/p/m-7.jpg',
            'duration' => 7200,
            'stream_url' => '/media/m-7/stream?sig=x',
        ]);
        $api = new ApiClient(self::BASE, $transport = $this->fullDiscovery()->json(200, ['session_id' => 's']));
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));
        $screen = $this->pickerOn(new CastScreen(new CastClient($api), $item, self::BASE, cols: 120, rows: 40));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        $this->runCmd($cmd);

        $body = json_decode($transport->requests[4]['body'], true);
        self::assertSame('https://srv.example/media/m-7/stream?sig=x', $body['media_url']);
        // Chromecast has no duration fallback issue — 7200 from `duration`.
        self::assertSame(7200, $body['duration']);
    }

    public function testCastSendAirPlayAbsoluteUrlAndPoster(): void
    {
        // Pick the AirPlay device (index 2) and assert the send goes to /stream.
        $transport = $this->fullDiscovery()->json(200, ['status' => 'streaming', 'session_id' => 'sess-ap']);
        $screen = $this->picker($transport);
        [$onAirplay] = $screen->update(new KeyMsg(KeyType::Down));
        [$onAirplay] = $onAirplay->update(new KeyMsg(KeyType::Down));

        [, $cmd] = $onAirplay->update(new KeyMsg(KeyType::Enter));
        $this->runCmd($cmd);

        $req = $transport->requests[4];
        self::assertStringContainsString('/api/v1/airplay/devices/ap-1/stream', $req['url']);
        $body = json_decode($req['body'], true);
        self::assertSame('https://srv.example/media/m-7/stream?sig=x', $body['audio_url']);
    }

    // ---- transport -----------------------------------------------------

    /** A screen already in Transport, bound to $backend, with the poll armed. */
    private function transport(CastBackend $backend): CastScreen
    {
        $screen = $this->picker($this->fullDiscovery());
        [$next] = $screen->update(new CastStartedMsg($this->device($backend)));

        return $next;
    }

    public function testTransportRendersTheHeader(): void
    {
        $screen = $this->transport(CastBackend::Chromecast);

        $view = $screen->view();
        self::assertStringContainsString('Casting to', $view);
        self::assertStringContainsString('The Matrix', $view);
        self::assertStringContainsString('pause/resume', $view);
    }

    public function testEnteringTransportBumpsThePollEpoch(): void
    {
        $screen = $this->picker($this->fullDiscovery());
        self::assertSame(0, $screen->pollEpoch());

        [$next] = $screen->update(new CastStartedMsg($this->device(CastBackend::Chromecast)));

        self::assertSame(1, $next->pollEpoch());
    }

    public function testSpacePausesThenResumes(): void
    {
        $transport = $this->fullDiscovery()
            ->json(200, ['state' => 'PAUSED'])   // pause
            ->json(200, ['state' => 'PLAYING']); // resume
        $screen = $this->picker($transport);
        [$tp] = $screen->update(new CastStartedMsg($this->device(CastBackend::Chromecast)));

        [$paused, $pauseCmd] = $tp->update(new KeyMsg(KeyType::Space));
        self::assertTrue($paused->isPaused());
        $this->runCmd($pauseCmd);
        self::assertStringContainsString('/api/v1/cast/devices/id-1/pause', $transport->requests[4]['url']);

        [$resumed, $resumeCmd] = $paused->update(new KeyMsg(KeyType::Space));
        self::assertFalse($resumed->isPaused());
        $this->runCmd($resumeCmd);
        self::assertStringContainsString('/api/v1/cast/devices/id-1/play', $transport->requests[5]['url']);
    }

    public function testSpaceOnDlnaPausesButNeverResumes(): void
    {
        // DLNA has no resume — once paused, Space only ever pauses (no resume request).
        $transport = $this->fullDiscovery()->json(200, ['state' => 'PAUSED']); // the pause
        $screen = $this->picker($transport);
        [$tp] = $screen->update(new CastStartedMsg($this->device(CastBackend::Dlna)));

        // The resume hint is absent for DLNA.
        self::assertStringNotContainsString('pause/resume', $tp->view());
        self::assertStringContainsString('Space  pause', $tp->view());

        [$paused, $pauseCmd] = $tp->update(new KeyMsg(KeyType::Space));
        self::assertTrue($paused->isPaused());
        $this->runCmd($pauseCmd);
        $countAfterPause = $transport->requestCount();

        // Space again while paused → no-op (no resume request issued).
        [$still, $cmd] = $paused->update(new KeyMsg(KeyType::Space));
        self::assertTrue($still->isPaused());
        self::assertNull($cmd);
        self::assertSame($countAfterPause, $transport->requestCount());
    }

    public function testStopOnlyWhenCanStop(): void
    {
        // Chromecast can stop → x stops then returns to the picker.
        $transport = $this->fullDiscovery()->json(200, ['state' => 'IDLE']);
        $screen = $this->picker($transport);
        [$tp] = $screen->update(new CastStartedMsg($this->device(CastBackend::Chromecast)));
        self::assertStringContainsString('x  stop', $tp->view());

        [$next, $cmd] = $tp->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame('picker', $next->mode());
        $this->runCmd($cmd);
        self::assertStringContainsString('/api/v1/cast/devices/id-1/stop', $transport->requests[4]['url']);
    }

    public function testStopIgnoredForRoku(): void
    {
        // Roku cannot stop — the key is ignored and the hint omits stop.
        $screen = $this->picker($this->fullDiscovery());
        [$tp] = $screen->update(new CastStartedMsg($this->device(CastBackend::Roku)));

        self::assertStringNotContainsString('x  stop', $tp->view());

        [$next, $cmd] = $tp->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame($tp, $next);
        self::assertNull($cmd);
    }

    public function testStatusTickFiresStatusAndReArms(): void
    {
        $transport = $this->fullDiscovery()->json(200, ['device_id' => 'id-1', 'active' => true, 'state' => 'PLAYING']);
        $screen = $this->picker($transport);
        [$tp] = $screen->update(new CastStartedMsg($this->device(CastBackend::Chromecast)));
        $epoch = $tp->pollEpoch();

        [$same, $cmd] = $tp->update(new CastStatusTickMsg($epoch));
        self::assertSame($tp, $same);

        // The batch holds the status fetch (→ a loaded Msg) and a re-armed tick.
        $msgs = $this->collectCmd($cmd);
        $loaded = array_values(array_filter($msgs, static fn (Msg $m): bool => $m instanceof CastStatusLoadedMsg));
        self::assertCount(1, $loaded);
        self::assertStringContainsString('/api/v1/cast/devices/id-1/status', $transport->requests[4]['url']);
    }

    public function testStaleStatusTickIsDropped(): void
    {
        $screen = $this->picker($this->fullDiscovery());
        [$tp] = $screen->update(new CastStartedMsg($this->device(CastBackend::Chromecast)));

        // A tick from a superseded epoch (e.g. after a device switch) is ignored.
        [$same, $cmd] = $tp->update(new CastStatusTickMsg($tp->pollEpoch() - 1));

        self::assertSame($tp, $same);
        self::assertNull($cmd);
    }

    public function testStatusTickInPickerModeIsDropped(): void
    {
        $screen = $this->picker($this->fullDiscovery());

        [$same, $cmd] = $screen->update(new CastStatusTickMsg($screen->pollEpoch()));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testStatusLoadedUpdatesTheState(): void
    {
        $tp = $this->transport(CastBackend::Chromecast);

        [$next] = $tp->update(new CastStatusLoadedMsg($tp->pollEpoch(), new CastStatus(true, 'BUFFERING')));

        self::assertSame('BUFFERING', $next->state());
        self::assertFalse($next->isPaused());
    }

    public function testStatusLoadedPausedFlag(): void
    {
        $tp = $this->transport(CastBackend::Chromecast);

        [$next] = $tp->update(new CastStatusLoadedMsg($tp->pollEpoch(), new CastStatus(true, 'PAUSED')));

        self::assertTrue($next->isPaused());
    }

    public function testStatusLoadedWithoutStateDerivesFromActive(): void
    {
        $tp = $this->transport(CastBackend::Chromecast);

        [$active] = $tp->update(new CastStatusLoadedMsg($tp->pollEpoch(), new CastStatus(true, null)));
        self::assertSame('playing', $active->state());

        [$idle] = $tp->update(new CastStatusLoadedMsg($tp->pollEpoch(), new CastStatus(false, null)));
        self::assertSame('idle', $idle->state());
    }

    public function testStaleStatusLoadedIsDropped(): void
    {
        $tp = $this->transport(CastBackend::Chromecast);

        [$next] = $tp->update(new CastStatusLoadedMsg($tp->pollEpoch() - 1, new CastStatus(true, 'PLAYING')));

        self::assertSame($tp, $next, 'a status for a superseded epoch is ignored');
        self::assertNull($next->state());
    }

    public function testRefreshKeyFetchesStatusNow(): void
    {
        $transport = $this->fullDiscovery()->json(200, ['device_id' => 'id-1', 'active' => true, 'state' => 'PLAYING']);
        $screen = $this->picker($transport);
        [$tp] = $screen->update(new CastStartedMsg($this->device(CastBackend::Chromecast)));

        [$same, $cmd] = $tp->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertSame($tp, $same);

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(CastStatusLoadedMsg::class, $msg);
    }

    public function testActionDoneAdoptsTheNewState(): void
    {
        $tp = $this->transport(CastBackend::Chromecast);

        [$next] = $tp->update(new CastActionDoneMsg('PLAYING'));
        self::assertSame('PLAYING', $next->state());

        // An empty state is ignored (keeps the last-known line).
        [$kept] = $next->update(new CastActionDoneMsg(''));
        self::assertSame('PLAYING', $kept->state());
    }

    public function testActionFailureToastsAndStaysInTransport(): void
    {
        $tp = $this->transport(CastBackend::Chromecast);

        [$next, $cmd] = $tp->update(new CastActionFailedMsg('pause failed'));

        self::assertSame('transport', $next->mode());
        $toast = $cmd?->__invoke();
        self::assertInstanceOf(ShowToastMsg::class, $toast);
        self::assertSame('pause failed', $toast->message);
    }

    public function testTransportActionAuthErrorSurfacesSessionExpiry(): void
    {
        // Discovery succeeds, then the pause POST 401s with no refresh token → the
        // action command maps the AuthError to a session expiry.
        $transport = $this->fullDiscovery()->json(401, ['error' => 'expired']);
        $screen = $this->pickerOn($this->screenNoRefresh($transport));
        [$tp] = $screen->update(new CastStartedMsg($this->device(CastBackend::Chromecast)));

        [, $cmd] = $tp->update(new KeyMsg(KeyType::Space));
        $msg = $this->runCmd($cmd);

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testEscFromTransportReturnsToPickerAndDropsThePoll(): void
    {
        $tp = $this->transport(CastBackend::Chromecast);
        $epoch = $tp->pollEpoch();

        [$next, $cmd] = $tp->update(new KeyMsg(KeyType::Escape));

        self::assertSame('picker', $next->mode());
        self::assertNull($next->activeDevice(), 'the session is left playing but unbound from transport');
        self::assertSame($epoch + 1, $next->pollEpoch(), 'the epoch bump strands the poll');
        self::assertNull($cmd, 'Esc does NOT stop the remote session');
    }

    public function testUnhandledTransportKeyIsANoOp(): void
    {
        $tp = $this->transport(CastBackend::Chromecast);

        [$next, $cmd] = $tp->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertSame($tp, $next);
        self::assertNull($cmd);
    }

    public function testStatusPollAuthErrorSurfacesSessionExpiry(): void
    {
        // Discovery succeeds (4 JSON legs), then the status GET 401s with no
        // refresh token → an AuthError surfaces as a session expiry.
        $transport = $this->fullDiscovery()->json(401, ['error' => 'expired']);
        $screen = $this->pickerOn($this->screenNoRefresh($transport));
        [$tp] = $screen->update(new CastStartedMsg($this->device(CastBackend::Chromecast)));

        $msg = $this->runCmd($tp->update(new KeyMsg(KeyType::Char, 'r'))[1]);

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testStatusPollNonAuthErrorIsSwallowed(): void
    {
        $transport = $this->fullDiscovery()->fail(new \RuntimeException('timeout'));
        $screen = $this->picker($transport);
        [$tp] = $screen->update(new CastStartedMsg($this->device(CastBackend::Chromecast)));

        $msg = $this->runCmd($tp->update(new KeyMsg(KeyType::Char, 'r'))[1]);

        self::assertNull($msg, 'a failed status poll keeps the last-known line, never crashes');
    }

    public function testCastSendAuthErrorSurfacesSessionExpiry(): void
    {
        // Discovery succeeds (4 JSON legs), then the cast POST 401s with no refresh
        // token → an AuthError surfaces as a session expiry.
        $transport = $this->fullDiscovery()->json(401, ['error' => 'expired']);
        $screen = $this->pickerOn($this->screenNoRefresh($transport));

        $msg = $this->runCmd($screen->update(new KeyMsg(KeyType::Enter))[1]);

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- resize / immutability -----------------------------------------

    public function testResizeUpdatesDimensions(): void
    {
        $screen = $this->picker($this->fullDiscovery());

        [$next] = $screen->update(new WindowSizeMsg(60, 20));

        self::assertNotSame($screen, $next);
        self::assertIsString($next->view());
    }

    public function testWithThemeIsImmutable(): void
    {
        $screen = $this->picker($this->fullDiscovery());
        $themed = $screen->withTheme(Theme::midnight());

        self::assertNotSame($screen, $themed);
        self::assertSame('picker', $themed->mode());
    }

    public function testWithCrumbsIsImmutable(): void
    {
        $screen = $this->picker($this->fullDiscovery());
        $crumbed = $screen->withCrumbs(['Home', 'Detail']);

        self::assertNotSame($screen, $crumbed);
        self::assertSame('Cast', $crumbed->crumbLabel());
    }

    public function testUnhandledMsgIsANoOp(): void
    {
        $screen = $this->picker($this->fullDiscovery());

        [$next, $cmd] = $screen->update(new class implements Msg {});

        self::assertSame($screen, $next);
        self::assertNull($cmd);
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

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Cast;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\Cast\CastBackend;
use Phlix\Console\Api\Cast\CastClient;
use Phlix\Console\Api\Dto\Cast\CastDevice;
use Phlix\Console\Api\Dto\Cast\CastStatus;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class CastClientTest extends TestCase
{
    private const BASE = 'https://srv.example';

    private function clientWith(FakeTransport $transport): CastClient
    {
        $api = new ApiClient(self::BASE, $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new CastClient($api);
    }

    /** All four discovery legs scripted with non-empty lists (cc, roku, airplay, dlna order). */
    private function fullDiscoveryTransport(): FakeTransport
    {
        return (new FakeTransport())
            ->json(200, ['devices' => [['device_id' => 'cc-1', 'name' => 'Chromecast TV']], 'count' => 1])
            ->json(200, ['devices' => [['device_id' => 'roku-1', 'name' => 'Roku']], 'count' => 1])
            ->json(200, ['devices' => [['device_id' => 'ap-1', 'name' => 'Apple TV', 'supports_video' => true]], 'count' => 1])
            ->json(200, ['renderers' => [['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV']], 'count' => 1]);
    }

    // ---- discover ------------------------------------------------------

    public function testDiscoverFansOutToAllFourBackends(): void
    {
        $transport = $this->fullDiscoveryTransport();

        $devices = $this->await($this->clientWith($transport)->discover());

        self::assertContainsOnlyInstancesOf(CastDevice::class, $devices);
        self::assertSame(4, $transport->requestCount());

        $urls = array_map(static fn (array $r): string => $r['url'], $transport->requests);
        self::assertStringContainsString('/api/v1/cast/devices', $urls[0]);
        self::assertStringContainsString('/api/v1/roku/devices', $urls[1]);
        self::assertStringContainsString('/api/v1/airplay/devices', $urls[2]);
        self::assertStringContainsString('/api/v1/dlna/renderers', $urls[3]);
    }

    public function testDiscoverFlattensInBackendOrder(): void
    {
        $devices = $this->await($this->clientWith($this->fullDiscoveryTransport())->discover());

        self::assertCount(4, $devices);
        self::assertSame(CastBackend::Chromecast, $devices[0]->backend);
        self::assertSame('cc-1', $devices[0]->id);
        self::assertSame(CastBackend::Roku, $devices[1]->backend);
        self::assertSame(CastBackend::AirPlay, $devices[2]->backend);
        self::assertTrue($devices[2]->supportsVideo);
        self::assertSame(CastBackend::Dlna, $devices[3]->backend);
        self::assertSame('uuid:1', $devices[3]->id);
        self::assertSame('Samsung TV', $devices[3]->name);
    }

    public function testDiscoverReadsTopLevelDevicesAndRenderers(): void
    {
        // The DLNA list lives under `renderers`, the rest under `devices`. A `data`
        // wrapper must NOT be read (cast routes are top-level, never enveloped).
        $devices = $this->await($this->clientWith($this->fullDiscoveryTransport())->discover());

        self::assertCount(4, $devices);
    }

    public function testDiscoverIsFaultTolerantWhenOneBackendRejects(): void
    {
        // The Roku leg (2nd) is a transport failure; the others still resolve and
        // the aggregate NEVER rejects — the headline guarantee.
        $transport = (new FakeTransport())
            ->json(200, ['devices' => [['device_id' => 'cc-1', 'name' => 'Chromecast TV']]])
            ->fail(new \RuntimeException('roku unreachable'))
            ->json(200, ['devices' => [['device_id' => 'ap-1', 'name' => 'Apple TV']]])
            ->json(200, ['renderers' => [['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV']]]);

        $devices = $this->await($this->clientWith($transport)->discover());

        self::assertCount(3, $devices, 'the failing Roku slice is empty, the rest survive');
        self::assertSame(CastBackend::Chromecast, $devices[0]->backend);
        self::assertSame(CastBackend::AirPlay, $devices[1]->backend);
        self::assertSame(CastBackend::Dlna, $devices[2]->backend);
    }

    public function testDiscoverIsFaultTolerantWhenABackend404s(): void
    {
        // A 404 (manager not configured) rejects via ApiError → that slice is empty.
        $transport = (new FakeTransport())
            ->json(404, ['error' => 'not found'])
            ->json(404, ['error' => 'not found'])
            ->json(404, ['error' => 'not found'])
            ->json(200, ['renderers' => [['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV']]]);

        $devices = $this->await($this->clientWith($transport)->discover());

        self::assertCount(1, $devices);
        self::assertSame(CastBackend::Dlna, $devices[0]->backend);
    }

    public function testDiscoverYieldsAnEmptySliceForANonArrayList(): void
    {
        // A backend whose `devices` is a non-array (or missing) contributes nothing.
        $transport = (new FakeTransport())
            ->json(200, ['devices' => 'oops'])
            ->json(200, [])
            ->json(200, ['devices' => [['device_id' => 'ap-1', 'name' => 'Apple TV']]])
            ->json(200, ['renderers' => 'nope']);

        $devices = $this->await($this->clientWith($transport)->discover());

        self::assertCount(1, $devices);
        self::assertSame(CastBackend::AirPlay, $devices[0]->backend);
    }

    public function testDiscoverSkipsNonArrayRows(): void
    {
        $transport = (new FakeTransport())
            ->json(200, ['devices' => [['device_id' => 'cc-1', 'name' => 'TV'], 'junk', 42]])
            ->json(200, ['devices' => []])
            ->json(200, ['devices' => []])
            ->json(200, ['renderers' => []]);

        $devices = $this->await($this->clientWith($transport)->discover());

        self::assertCount(1, $devices);
        self::assertSame('cc-1', $devices[0]->id);
    }

    public function testDiscoverIgnoresAnEnvelopeDataWrapper(): void
    {
        // Regression guard (mirroring the admin surfaces): a dashboard-style
        // `{data:{devices}}` wrapper must yield `[]` for that backend — the list is
        // read top-level, NOT from `data`.
        $transport = (new FakeTransport())
            ->json(200, ['data' => ['devices' => [['device_id' => 'ghost', 'name' => 'Ghost']]]])
            ->json(200, ['devices' => []])
            ->json(200, ['devices' => []])
            ->json(200, ['data' => ['renderers' => [['udn' => 'ghost', 'friendly_name' => 'Ghost']]]]);

        $devices = $this->await($this->clientWith($transport)->discover());

        self::assertSame([], $devices, 'an enveloped data wrapper contributes no devices');
    }

    public function testDiscoverAttachesTheBearerToken(): void
    {
        $transport = $this->fullDiscoveryTransport();

        $this->await($this->clientWith($transport)->discover());

        self::assertSame('Bearer access-1', $transport->requestAt(0)['headers']['Authorization'] ?? null);
    }

    // ---- castTo --------------------------------------------------------

    public function testCastToChromecastPostsTheCastBodyWithDuration(): void
    {
        $transport = (new FakeTransport())->json(200, ['session_id' => 'sess-1', 'device_id' => 'cc-1', 'state' => 'PLAYING']);
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV']);

        $sessionId = $this->await($this->clientWith($transport)->castTo($device, 'item-9', 'http://x/stream.mp4', 'Heat', 'http://x/poster.jpg', 7200));

        self::assertSame('sess-1', $sessionId);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/cast/devices/cc-1/cast', $transport->requestAt(0)['url']);
        $body = $this->body($transport, 0);
        self::assertSame('http://x/stream.mp4', $body['media_url']);
        self::assertSame('video/mp4', $body['mime_type']);
        self::assertSame('Heat', $body['title']);
        self::assertSame(7200, $body['duration']);
    }

    public function testCastToChromecastDefaultsDurationToZero(): void
    {
        $transport = (new FakeTransport())->json(200, ['session_id' => 'sess-1']);
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV']);

        $this->await($this->clientWith($transport)->castTo($device, 'item-9', 'http://x/s.mp4', 'Heat', null, null));

        self::assertSame(0, $this->body($transport, 0)['duration']);
    }

    public function testCastToRokuPostsSendWithThumbnail(): void
    {
        $transport = (new FakeTransport())->json(200, ['session_id' => 'sess-r', 'state' => 'play']);
        $device = CastDevice::fromRoku(['device_id' => 'roku-1', 'name' => 'Roku']);

        $sessionId = $this->await($this->clientWith($transport)->castTo($device, 'item-9', 'http://x/s.mp4', 'Heat', 'http://x/poster.jpg', 120));

        self::assertSame('sess-r', $sessionId);
        self::assertStringContainsString('/api/v1/roku/devices/roku-1/send', $transport->requestAt(0)['url']);
        $body = $this->body($transport, 0);
        self::assertSame('http://x/s.mp4', $body['media_url']);
        self::assertSame('video/mp4', $body['mime_type']);
        self::assertSame('Heat', $body['title']);
        self::assertSame('http://x/poster.jpg', $body['thumbnail']);
    }

    public function testCastToRokuDefaultsThumbnailToEmpty(): void
    {
        $transport = (new FakeTransport())->json(200, ['session_id' => 'sess-r']);
        $device = CastDevice::fromRoku(['device_id' => 'roku-1', 'name' => 'Roku']);

        $this->await($this->clientWith($transport)->castTo($device, 'item-9', 'http://x/s.mp4', 'Heat', null, 120));

        self::assertSame('', $this->body($transport, 0)['thumbnail']);
    }

    public function testCastToAirPlayUsesAudioUrlAndContentType(): void
    {
        $transport = (new FakeTransport())->json(200, ['status' => 'streaming', 'session_id' => 'sess-a', 'state' => 'streaming']);
        $device = CastDevice::fromAirPlay(['device_id' => 'ap-1', 'name' => 'Apple TV']);

        $sessionId = $this->await($this->clientWith($transport)->castTo($device, 'item-9', 'http://x/s.mp4', 'Heat', null, 90));

        self::assertSame('sess-a', $sessionId);
        self::assertStringContainsString('/api/v1/airplay/devices/ap-1/stream', $transport->requestAt(0)['url']);
        $body = $this->body($transport, 0);
        self::assertSame('http://x/s.mp4', $body['audio_url'], 'AirPlay uses audio_url, not media_url');
        self::assertSame('video/mp4', $body['content_type'], 'AirPlay uses content_type, not mime_type');
        self::assertSame(90, $body['duration']);
        self::assertArrayNotHasKey('media_url', $body);
        self::assertArrayNotHasKey('mime_type', $body);
    }

    public function testCastToDlnaSendsMediaItemIdAndUri(): void
    {
        $transport = (new FakeTransport())->json(200, ['session_id' => 'sess-d', 'state' => 'PLAYING']);
        $device = CastDevice::fromDlna(['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV']);

        $sessionId = $this->await($this->clientWith($transport)->castTo($device, 'item-9', 'http://x/s.mp4', 'Heat', null, 90));

        self::assertSame('sess-d', $sessionId);
        self::assertStringContainsString('/api/v1/dlna/renderers/uuid%3A1/play', $transport->requestAt(0)['url']);
        $body = $this->body($transport, 0);
        self::assertSame('item-9', $body['media_item_id'], 'DLNA needs the media item id, not the device id');
        self::assertSame('http://x/s.mp4', $body['uri']);
    }

    public function testCastToFallsBackToStateWhenNoSessionId(): void
    {
        $transport = (new FakeTransport())->json(200, ['state' => 'PLAYING']);
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV']);

        $result = $this->await($this->clientWith($transport)->castTo($device, 'item-9', 'http://x/s.mp4', 'Heat', null, 0));

        self::assertSame('PLAYING', $result);
    }

    public function testCastToRejectsWithTheServerErrorOnNon2xx(): void
    {
        $transport = (new FakeTransport())->json(400, ['error' => 'media_url is required']);
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV']);

        $error = $this->awaitError($this->clientWith($transport)->castTo($device, 'item-9', '', 'Heat', null, 0));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertStringContainsString('media_url is required', $error->getMessage());
    }

    // ---- pause ---------------------------------------------------------

    public function testPauseChromecastPostsPause(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'state' => 'PAUSED']);
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV']);

        $state = $this->await($this->clientWith($transport)->pause($device));

        self::assertSame('PAUSED', $state);
        self::assertSame('POST', $transport->requestAt(0)['method']);
        self::assertStringContainsString('/api/v1/cast/devices/cc-1/pause', $transport->requestAt(0)['url']);
    }

    public function testPauseRokuPostsPlayKeyToggle(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'state' => 'paused']);
        $device = CastDevice::fromRoku(['device_id' => 'roku-1', 'name' => 'Roku']);

        $state = $this->await($this->clientWith($transport)->pause($device));

        self::assertSame('paused', $state);
        self::assertStringContainsString('/api/v1/roku/devices/roku-1/key/Play', $transport->requestAt(0)['url']);
    }

    public function testPauseAirPlayPostsPause(): void
    {
        $transport = (new FakeTransport())->json(200, ['status' => 'paused']);
        $device = CastDevice::fromAirPlay(['device_id' => 'ap-1', 'name' => 'Apple TV']);

        $this->await($this->clientWith($transport)->pause($device));

        self::assertStringContainsString('/api/v1/airplay/devices/ap-1/pause', $transport->requestAt(0)['url']);
    }

    public function testPauseDlnaPostsPause(): void
    {
        $transport = (new FakeTransport())->json(200, ['state' => 'PAUSED_PLAYBACK']);
        $device = CastDevice::fromDlna(['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV']);

        $state = $this->await($this->clientWith($transport)->pause($device));

        self::assertSame('PAUSED_PLAYBACK', $state);
        self::assertStringContainsString('/api/v1/dlna/renderers/uuid%3A1/pause', $transport->requestAt(0)['url']);
    }

    // ---- resume --------------------------------------------------------

    public function testResumeChromecastPostsPlay(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'state' => 'PLAYING']);
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV']);

        $state = $this->await($this->clientWith($transport)->resume($device));

        self::assertSame('PLAYING', $state);
        self::assertStringContainsString('/api/v1/cast/devices/cc-1/play', $transport->requestAt(0)['url']);
    }

    public function testResumeRokuPostsPlayKeyToggle(): void
    {
        $transport = (new FakeTransport())->json(200, ['state' => 'play']);
        $device = CastDevice::fromRoku(['device_id' => 'roku-1', 'name' => 'Roku']);

        $this->await($this->clientWith($transport)->resume($device));

        self::assertStringContainsString('/api/v1/roku/devices/roku-1/key/Play', $transport->requestAt(0)['url']);
    }

    public function testResumeAirPlayPostsResume(): void
    {
        $transport = (new FakeTransport())->json(200, ['status' => 'streaming', 'state' => 'streaming']);
        $device = CastDevice::fromAirPlay(['device_id' => 'ap-1', 'name' => 'Apple TV']);

        $state = $this->await($this->clientWith($transport)->resume($device));

        self::assertSame('streaming', $state);
        self::assertStringContainsString('/api/v1/airplay/devices/ap-1/resume', $transport->requestAt(0)['url']);
    }

    public function testResumeDlnaResolvesEmptyWithoutARequest(): void
    {
        $transport = new FakeTransport();
        $device = CastDevice::fromDlna(['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV']);

        $state = $this->await($this->clientWith($transport)->resume($device));

        self::assertSame('', $state);
        self::assertSame(0, $transport->requestCount(), 'DLNA has no resume endpoint — no request issued');
    }

    // ---- stop ----------------------------------------------------------

    public function testStopChromecastPostsStop(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'message' => 'stopped', 'state' => 'IDLE']);
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV']);

        $state = $this->await($this->clientWith($transport)->stop($device));

        self::assertSame('IDLE', $state);
        self::assertStringContainsString('/api/v1/cast/devices/cc-1/stop', $transport->requestAt(0)['url']);
    }

    public function testStopAirPlayPostsStop(): void
    {
        $transport = (new FakeTransport())->json(200, ['status' => 'stopped']);
        $device = CastDevice::fromAirPlay(['device_id' => 'ap-1', 'name' => 'Apple TV']);

        $this->await($this->clientWith($transport)->stop($device));

        self::assertStringContainsString('/api/v1/airplay/devices/ap-1/stop', $transport->requestAt(0)['url']);
    }

    public function testStopDlnaPostsStop(): void
    {
        $transport = (new FakeTransport())->json(200, ['state' => 'STOPPED']);
        $device = CastDevice::fromDlna(['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV']);

        $state = $this->await($this->clientWith($transport)->stop($device));

        self::assertSame('STOPPED', $state);
        self::assertStringContainsString('/api/v1/dlna/renderers/uuid%3A1/stop', $transport->requestAt(0)['url']);
    }

    public function testStopRokuResolvesEmptyWithoutARequest(): void
    {
        $transport = new FakeTransport();
        $device = CastDevice::fromRoku(['device_id' => 'roku-1', 'name' => 'Roku']);

        $state = $this->await($this->clientWith($transport)->stop($device));

        self::assertSame('', $state);
        self::assertSame(0, $transport->requestCount(), 'Roku has no reliable Stop — no request issued');
    }

    // ---- seek ----------------------------------------------------------

    public function testSeekChromecastSendsPositionMs(): void
    {
        $transport = (new FakeTransport())->json(200, ['success' => true, 'position_ms' => 5000, 'state' => 'PLAYING']);
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV']);

        $state = $this->await($this->clientWith($transport)->seek($device, 5000));

        self::assertSame('PLAYING', $state);
        self::assertStringContainsString('/api/v1/cast/devices/cc-1/seek', $transport->requestAt(0)['url']);
        self::assertSame(5000, $this->body($transport, 0)['position_ms']);
    }

    public function testSeekDlnaConvertsMsToTicks(): void
    {
        $transport = (new FakeTransport())->json(200, ['position' => '00:00:05']);
        $device = CastDevice::fromDlna(['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV']);

        $this->await($this->clientWith($transport)->seek($device, 5000));

        self::assertStringContainsString('/api/v1/dlna/renderers/uuid%3A1/seek', $transport->requestAt(0)['url']);
        self::assertSame(50000000, $this->body($transport, 0)['position_ticks'], 'ticks = ms * 10000');
    }

    public function testSeekRokuResolvesEmptyWithoutARequest(): void
    {
        $transport = new FakeTransport();
        $device = CastDevice::fromRoku(['device_id' => 'roku-1', 'name' => 'Roku']);

        $state = $this->await($this->clientWith($transport)->seek($device, 5000));

        self::assertSame('', $state);
        self::assertSame(0, $transport->requestCount(), 'Roku cannot seek — no request issued');
    }

    public function testSeekAirPlayResolvesEmptyWithoutARequest(): void
    {
        $transport = new FakeTransport();
        $device = CastDevice::fromAirPlay(['device_id' => 'ap-1', 'name' => 'Apple TV']);

        $state = $this->await($this->clientWith($transport)->seek($device, 5000));

        self::assertSame('', $state);
        self::assertSame(0, $transport->requestCount(), 'AirPlay cannot seek — no request issued');
    }

    // ---- status --------------------------------------------------------

    public function testStatusMapsAnActiveShape(): void
    {
        $transport = (new FakeTransport())->json(200, ['device_id' => 'cc-1', 'active' => true, 'session_id' => 's', 'state' => 'PLAYING']);
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV']);

        $status = $this->await($this->clientWith($transport)->status($device));

        self::assertInstanceOf(CastStatus::class, $status);
        self::assertTrue($status->active);
        self::assertSame('PLAYING', $status->state);
        self::assertStringContainsString('/api/v1/cast/devices/cc-1/status', $transport->requestAt(0)['url']);
        self::assertSame('GET', $transport->requestAt(0)['method']);
    }

    public function testStatusMapsAnInactiveDlnaShape(): void
    {
        $transport = (new FakeTransport())->json(200, ['renderer_id' => 'uuid:1', 'has_active_session' => false]);
        $device = CastDevice::fromDlna(['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV']);

        $status = $this->await($this->clientWith($transport)->status($device));

        self::assertFalse($status->active);
        self::assertNull($status->state);
        self::assertStringContainsString('/api/v1/dlna/renderers/uuid%3A1/status', $transport->requestAt(0)['url']);
    }

    public function testStatusRejectsWithTheServerErrorOnNon2xx(): void
    {
        $transport = (new FakeTransport())->json(404, ['error' => 'device not found']);
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV']);

        $error = $this->awaitError($this->clientWith($transport)->status($device));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertStringContainsString('device not found', $error->getMessage());
    }

    // ---- helpers -------------------------------------------------------

    /** @return array<string,mixed> */
    private function body(FakeTransport $transport, int $index): array
    {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($transport->requestAt($index)['body'], true);

        return $decoded;
    }

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($value) use (&$state): void {
                $state['value'] = $value;
                $state['done'] = true;
                Loop::stop();
            },
            function ($error) use (&$state): void {
                $state['error'] = $error;
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

    private function awaitError(PromiseInterface $promise): \Throwable
    {
        try {
            $this->await($promise);
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('expected the promise to reject');
    }
}

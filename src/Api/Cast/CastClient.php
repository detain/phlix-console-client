<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Cast;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Cast\CastDevice;
use Phlix\Console\Api\Dto\Cast\CastStatus;
use Phlix\Console\Api\Dto\Coerce;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * The typed client for the server's four cast backends (Chromecast, Roku,
 * AirPlay, DLNA), layered over {@see ApiClient::send()}.
 *
 * All cast routes are UNAUTHED + TOP-LEVEL (no `{success, data}` envelope; the
 * Bearer header `send()` attaches is harmless), and each backend's routes only
 * exist when its manager is configured on the box. {@see discover()} is therefore
 * per-backend fault-tolerant: a backend that 404s / errors / returns a non-array
 * contributes zero devices and NEVER rejects the aggregate.
 *
 * Capability-gated transport verbs (DLNA resume, Roku stop, Roku/AirPlay seek)
 * resolve `''` synchronously via {@see resolve()} WITHOUT issuing a request,
 * honouring {@see CastBackend}'s capability flags.
 */
final class CastClient
{
    public function __construct(
        private readonly ApiClient $api,
    ) {
    }

    /**
     * Discover every reachable cast target across all four backends concurrently.
     * Each leg reads its top-level list (`devices` for cast/roku/airplay,
     * `renderers` for DLNA), maps the rows into {@see CastDevice}s, and — crucially
     * — has its OWN `->otherwise(fn () => [])` so one backend's failure yields an
     * empty slice rather than rejecting the whole fan-out. The four slices are
     * flattened in Chromecast → Roku → AirPlay → DLNA order.
     *
     * @return PromiseInterface<list<CastDevice>>
     */
    public function discover(): PromiseInterface
    {
        $legs = [
            $this->discoverBackend(
                CastBackend::Chromecast,
                'devices',
                static fn (array $row): CastDevice => CastDevice::fromChromecast($row),
            ),
            $this->discoverBackend(
                CastBackend::Roku,
                'devices',
                static fn (array $row): CastDevice => CastDevice::fromRoku($row),
            ),
            $this->discoverBackend(
                CastBackend::AirPlay,
                'devices',
                static fn (array $row): CastDevice => CastDevice::fromAirPlay($row),
            ),
            $this->discoverBackend(
                CastBackend::Dlna,
                'renderers',
                static fn (array $row): CastDevice => CastDevice::fromDlna($row),
            ),
        ];

        return all($legs)->then(static function (array $slices): array {
            /** @var list<CastDevice> $devices */
            $devices = array_merge(...$slices);

            return $devices;
        });
    }

    /**
     * One discovery leg: GET the backend's device/renderer list, map the top-level
     * `$key` rows into {@see CastDevice}s, and swallow any failure (404 / error /
     * non-array) into an empty slice so the aggregate never rejects.
     *
     * @param \Closure(array<array-key,mixed>): CastDevice $factory
     * @return PromiseInterface<list<CastDevice>>
     */
    private function discoverBackend(CastBackend $backend, string $key, \Closure $factory): PromiseInterface
    {
        return $this->api->send('GET', $backend->devicesPath())
            ->then(static fn (array $body): array => self::mapList($body[$key] ?? null, $factory))
            ->otherwise(static fn (\Throwable $e): array => []);
    }

    /**
     * Cast a media item to a device. Each backend takes a different send endpoint
     * and body (the field landmines are honoured: AirPlay uses `audio_url` +
     * `content_type`; DLNA needs both `media_item_id` and `uri`). Resolves the
     * `session_id` (or `state` when absent) string; rejects with the server `error`
     * on a non-2xx (the {@see \Phlix\Console\Api\ApiError} carries it).
     *
     * @return PromiseInterface<string>
     */
    public function castTo(
        CastDevice $device,
        string $mediaItemId,
        string $mediaUrl,
        string $title,
        ?string $thumbnail,
        ?int $durationSecs,
    ): PromiseInterface {
        [$suffix, $body] = match ($device->backend) {
            CastBackend::Chromecast => ['/cast', [
                'media_url' => $mediaUrl,
                'mime_type' => 'video/mp4',
                'title' => $title,
                'duration' => $durationSecs ?? 0,
            ]],
            CastBackend::Roku => ['/send', [
                'media_url' => $mediaUrl,
                'mime_type' => 'video/mp4',
                'title' => $title,
                'thumbnail' => $thumbnail ?? '',
            ]],
            CastBackend::AirPlay => ['/stream', [
                'audio_url' => $mediaUrl,
                'content_type' => 'video/mp4',
                'duration' => $durationSecs ?? 0,
            ]],
            CastBackend::Dlna => ['/play', [
                'media_item_id' => $mediaItemId,
                'uri' => $mediaUrl,
            ]],
        };

        return $this->api->send('POST', $device->backend->devicePath($device->id) . $suffix, [], $body)
            ->then(static fn (array $resp): string => Coerce::str($resp['session_id'] ?? ($resp['state'] ?? '')));
    }

    /**
     * Pause playback on a device. Roku has no pause endpoint — its ECP `Play` key
     * toggles play/pause — so Roku posts `…/key/Play`. Resolves the new `state`
     * (or `''`).
     *
     * @return PromiseInterface<string>
     */
    public function pause(CastDevice $device): PromiseInterface
    {
        $suffix = $device->backend === CastBackend::Roku ? '/key/Play' : '/pause';

        return $this->command($device, $suffix);
    }

    /**
     * Resume playback. Chromecast → `…/play`, Roku → `…/key/Play` (toggle), AirPlay
     * → `…/resume`. DLNA has NO resume endpoint, so this resolves `''` WITHOUT a
     * request (honouring `canResume() === false`). Resolves the new `state`.
     *
     * @return PromiseInterface<string>
     */
    public function resume(CastDevice $device): PromiseInterface
    {
        if (!$device->backend->canResume()) {
            return resolve('');
        }

        $suffix = match ($device->backend) {
            CastBackend::Chromecast => '/play',
            CastBackend::Roku => '/key/Play',
            default => '/resume',
        };

        return $this->command($device, $suffix);
    }

    /**
     * Stop playback. Chromecast/AirPlay/DLNA → `…/stop`. Roku has no reliable Stop,
     * so this resolves `''` WITHOUT a request (honouring `canStop() === false`).
     * Resolves the new `state` (or `''`).
     *
     * @return PromiseInterface<string>
     */
    public function stop(CastDevice $device): PromiseInterface
    {
        if (!$device->backend->canStop()) {
            return resolve('');
        }

        return $this->command($device, '/stop');
    }

    /**
     * Seek to $positionMs. Chromecast → `…/seek {position_ms}`; DLNA → `…/seek
     * {position_ticks}` (100-ns ticks = ms × 10000). Roku/AirPlay cannot seek, so
     * this resolves `''` WITHOUT a request (honouring `canSeek() === false`).
     * Resolves the new `state` (or `''`).
     *
     * @return PromiseInterface<string>
     */
    public function seek(CastDevice $device, int $positionMs): PromiseInterface
    {
        if (!$device->backend->canSeek()) {
            return resolve('');
        }

        $body = $device->backend === CastBackend::Dlna
            ? ['position_ticks' => $positionMs * 10000]
            : ['position_ms' => $positionMs];

        return $this->api->send('POST', $device->backend->devicePath($device->id) . '/seek', [], $body)
            ->then(static fn (array $resp): string => Coerce::str($resp['state'] ?? ''));
    }

    /**
     * Fetch a device's current playback status. GET `…/{id}/status` mapped into a
     * {@see CastStatus} (tolerant of either the active or inactive shape).
     *
     * @return PromiseInterface<CastStatus>
     */
    public function status(CastDevice $device): PromiseInterface
    {
        return $this->api->send('GET', $device->backend->devicePath($device->id) . '/status')
            ->then(static fn (array $body): CastStatus => CastStatus::fromArray($body));
    }

    /**
     * POST a bodiless transport command and resolve the new `state` (or `''`).
     *
     * @return PromiseInterface<string>
     */
    private function command(CastDevice $device, string $suffix): PromiseInterface
    {
        return $this->api->send('POST', $device->backend->devicePath($device->id) . $suffix)
            ->then(static fn (array $resp): string => Coerce::str($resp['state'] ?? ''));
    }

    /**
     * Map every array row of a loosely-typed list payload through $factory,
     * skipping any non-array entry. Returns a re-indexed `list<T>`.
     *
     * @template T
     * @param mixed                               $rows
     * @param \Closure(array<array-key,mixed>): T $factory
     * @return list<T>
     */
    private static function mapList(mixed $rows, \Closure $factory): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $factory($row);
            }
        }

        return $out;
    }
}

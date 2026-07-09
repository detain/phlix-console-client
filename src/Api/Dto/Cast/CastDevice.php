<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Cast;

use Phlix\Console\Api\Cast\CastBackend;
use Phlix\Console\Api\Dto\Coerce;

/**
 * A discovered cast target, unified across the four backends.
 *
 * Field landmines (per the verified server contracts): the id is `device_id` for
 * Chromecast/Roku/AirPlay but `udn` for DLNA; the name is `name` for the first
 * three but `friendly_name` for DLNA. `detail` carries the host/address for
 * cast/roku/airplay and the manufacturer for DLNA; `supportsVideo` is AirPlay's
 * `supports_video` (null elsewhere = unknown).
 *
 * Each factory is tolerant via {@see Coerce}, so a sparse / partial row never
 * breaks discovery. Immutable.
 */
final readonly class CastDevice
{
    public function __construct(
        public CastBackend $backend,
        public string $id,
        public string $name,
        public ?string $model,
        public ?string $detail,
        public ?bool $supportsVideo,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromChromecast(array $data): self
    {
        return new self(
            backend: CastBackend::Chromecast,
            id: Coerce::str($data['device_id'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            model: Coerce::nstr($data['model'] ?? null),
            detail: Coerce::nstr($data['address'] ?? ($data['host'] ?? null)),
            supportsVideo: null,
        );
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromRoku(array $data): self
    {
        return new self(
            backend: CastBackend::Roku,
            id: Coerce::str($data['device_id'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            model: Coerce::nstr($data['model'] ?? null),
            detail: Coerce::nstr($data['address'] ?? ($data['host'] ?? null)),
            supportsVideo: null,
        );
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromAirPlay(array $data): self
    {
        return new self(
            backend: CastBackend::AirPlay,
            id: Coerce::str($data['device_id'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            model: Coerce::nstr($data['model'] ?? null),
            detail: Coerce::nstr($data['address'] ?? ($data['host'] ?? null)),
            supportsVideo: isset($data['supports_video']) ? Coerce::bool($data['supports_video']) : null,
        );
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromDlna(array $data): self
    {
        return new self(
            backend: CastBackend::Dlna,
            id: Coerce::str($data['udn'] ?? ''),
            name: Coerce::str($data['friendly_name'] ?? ''),
            model: Coerce::nstr($data['model_name'] ?? null),
            detail: Coerce::nstr($data['manufacturer'] ?? null),
            supportsVideo: null,
        );
    }

    /** A human label: "{name} · {backend}". */
    public function label(): string
    {
        return $this->name . ' · ' . $this->backend->label();
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Cast;

/**
 * The four cast backends the server can expose, each carrying its own base path,
 * discovery endpoint name, and transport-capability knowledge. The console
 * aggregates discovery across all four and routes per-backend send/transport
 * commands through the matching paths.
 *
 * All cast routes are UNAUTHED + TOP-LEVEL (no `{success, data}` envelope), and a
 * backend's routes only exist if its manager is configured on the box — so a
 * backend may 404 / error; discovery tolerates that per backend.
 *
 * Capability flags (the UI/transport honour these): pause + status are ALWAYS
 * available; resume is everywhere except DLNA (no resume endpoint); stop is
 * everywhere except Roku (ECP has no reliable Stop key); seek is only Chromecast
 * + DLNA.
 */
enum CastBackend: string
{
    case Chromecast = 'chromecast';
    case Roku = 'roku';
    case AirPlay = 'airplay';
    case Dlna = 'dlna';

    /** The human-facing backend name. */
    public function label(): string
    {
        return match ($this) {
            self::Chromecast => 'Chromecast',
            self::Roku => 'Roku',
            self::AirPlay => 'AirPlay',
            self::Dlna => 'DLNA',
        };
    }

    /** The backend's API base path (no trailing slash). */
    public function basePath(): string
    {
        return match ($this) {
            self::Chromecast => '/api/v1/cast',
            self::Roku => '/api/v1/roku',
            self::AirPlay => '/api/v1/airplay',
            self::Dlna => '/api/v1/dlna',
        };
    }

    /**
     * The discovery endpoint: `{basePath}/devices` for cast/roku/airplay,
     * `{basePath}/renderers` for DLNA.
     */
    public function devicesPath(): string
    {
        return $this->basePath() . ($this === self::Dlna ? '/renderers' : '/devices');
    }

    /** The per-device endpoint base for a (raw) device id. */
    public function devicePath(string $id): string
    {
        return $this->devicesPath() . '/' . rawurlencode($id);
    }

    /** Whether the backend has a resume endpoint (everything except DLNA). */
    public function canResume(): bool
    {
        return $this !== self::Dlna;
    }

    /** Whether the backend has a reliable stop (everything except Roku). */
    public function canStop(): bool
    {
        return $this !== self::Roku;
    }

    /** Whether the backend supports seeking (Chromecast + DLNA only). */
    public function canSeek(): bool
    {
        return $this === self::Chromecast || $this === self::Dlna;
    }
}

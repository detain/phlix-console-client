<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The DLNA media-server status, mirroring `GET /api/v1/admin/dlna/status` →
 * TOP-LEVEL `{enabled, running, serverId, friendlyName, port, baseUrl, message?}`
 * (the {@see \Phlix\Server\Http\Controllers\Admin\AdminDlnaServerController} is
 * unenveloped — admin envelopes are per-controller).
 *
 * When the server is not configured the payload is
 * `{enabled:false, running:false, serverId:null, friendlyName:null, port:null,
 * baseUrl:null, message:'DLNA server not configured'}`; the defensive `fromArray`
 * tolerates any missing key by falling back to the not-configured defaults.
 *
 * Immutable.
 */
final readonly class DlnaServerStatus
{
    public function __construct(
        public bool $enabled,
        public bool $running,
        public ?string $serverId,
        public ?string $friendlyName,
        public ?int $port,
        public ?string $baseUrl,
        public ?string $message,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: Coerce::bool($data['enabled'] ?? false),
            running: Coerce::bool($data['running'] ?? false),
            serverId: Coerce::nstr($data['serverId'] ?? null),
            friendlyName: Coerce::nstr($data['friendlyName'] ?? null),
            port: Coerce::nint($data['port'] ?? null),
            baseUrl: Coerce::nstr($data['baseUrl'] ?? null),
            message: Coerce::nstr($data['message'] ?? null),
        );
    }

    /**
     * A short human label for the current state: "Running" when enabled and
     * up, "Stopped" when enabled but down, else "Not configured".
     */
    public function stateLabel(): string
    {
        if (!$this->enabled) {
            return 'Not configured';
        }

        return $this->running ? 'Running' : 'Stopped';
    }
}

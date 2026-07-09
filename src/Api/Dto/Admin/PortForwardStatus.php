<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The port-forward status, mirroring
 * `GET /api/v1/admin/remote/portforward/status` → TOP-LEVEL
 * `{enabled, method, externalIp, externalPort, hostname}` (the
 * {@see \Phlix\Server\Http\Controllers\Admin\AdminHubController} is unenveloped).
 *
 * A `{data:{...}}` wrapper yields the disabled default. Immutable.
 */
final readonly class PortForwardStatus
{
    public function __construct(
        public bool $enabled,
        public ?string $method,
        public ?string $externalIp,
        public ?int $externalPort,
        public ?string $hostname,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: Coerce::bool($data['enabled'] ?? false),
            method: Coerce::nstr($data['method'] ?? null),
            externalIp: Coerce::nstr($data['externalIp'] ?? null),
            externalPort: Coerce::nint($data['externalPort'] ?? null),
            hostname: Coerce::nstr($data['hostname'] ?? null),
        );
    }

    /** A short human label for the current state. */
    public function stateLabel(): string
    {
        return $this->enabled ? 'Enabled' : 'Disabled';
    }

    /** A one-line summary for the panel (the external endpoint when enabled). */
    public function summary(): string
    {
        if (!$this->enabled) {
            return 'Port forwarding disabled.';
        }

        $where = $this->hostname ?? $this->externalIp;
        if ($where !== null && $this->externalPort !== null) {
            return 'Forwarded via ' . $where . ':' . $this->externalPort . '.';
        }

        return 'Port forwarding enabled.';
    }
}

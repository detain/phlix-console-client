<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The relay-tunnel status, mirroring `GET /api/v1/admin/remote/relay/status` →
 * TOP-LEVEL `{connected, active, endpoint, establishedAt}` (the
 * {@see \Phlix\Server\Http\Controllers\Admin\AdminHubController} is unenveloped).
 * The server's `endpoint` is always null today; `establishedAt` is the last
 * tunnel activity time (or null).
 *
 * A `{data:{...}}` wrapper yields the disconnected default. Immutable.
 */
final readonly class RelayStatus
{
    public function __construct(
        public bool $connected,
        public bool $active,
        public ?string $endpoint,
        public ?string $establishedAt,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            connected: Coerce::bool($data['connected'] ?? false),
            active: Coerce::bool($data['active'] ?? false),
            endpoint: Coerce::nstr($data['endpoint'] ?? null),
            establishedAt: Coerce::nstr($data['establishedAt'] ?? null),
        );
    }

    /** A short human label for the current state. */
    public function stateLabel(): string
    {
        if ($this->connected) {
            return 'Connected';
        }

        // [review console__copywriter CONSOLIDATED M20] "Active (not
        // connected)" contradicts itself the moment you read it — "Active"
        // was the wrong word for a configured-but-down tunnel: the sibling
        // status DTOs spell liveness as Running/Stopped (DlnaServerStatus)
        // or Enabled/Disabled (PortForwardStatus), never both at once. The
        // state here is ENABLED (config) yet OFFLINE (liveness).
        return $this->active ? 'Enabled (offline)' : 'Disconnected';
    }

    /** A one-line summary for the panel. */
    public function summary(): string
    {
        if ($this->connected) {
            return 'Relay tunnel connected.';
        }

        return 'Relay tunnel disconnected.';
    }
}

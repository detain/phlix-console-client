<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The hub-pairing status, mirroring `GET /api/v1/admin/remote/hub/status` →
 * TOP-LEVEL `{paired:false}` when unpaired, else
 * `{paired:true, serverId, hubUrl, enrolledAt, lastHeartbeat}` (the
 * {@see \Phlix\Server\Http\Controllers\Admin\AdminHubController} is unenveloped —
 * admin envelopes are per-controller).
 *
 * `lastHeartbeat` is never persisted by the server today (always null), but is
 * carried for forward compatibility. A `{data:{...}}` wrapper yields the
 * unpaired default. Immutable.
 */
final readonly class HubStatus
{
    public function __construct(
        public bool $paired,
        public ?string $serverId,
        public ?string $hubUrl,
        public ?string $enrolledAt,
        public ?string $lastHeartbeat,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            paired: Coerce::bool($data['paired'] ?? false),
            serverId: Coerce::nstr($data['serverId'] ?? null),
            hubUrl: Coerce::nstr($data['hubUrl'] ?? null),
            enrolledAt: Coerce::nstr($data['enrolledAt'] ?? null),
            lastHeartbeat: Coerce::nstr($data['lastHeartbeat'] ?? null),
        );
    }

    /** A short human label for the current state. */
    public function stateLabel(): string
    {
        return $this->paired ? 'Paired' : 'Not paired';
    }

    /** A one-line summary for the panel (the hub URL when paired). */
    public function summary(): string
    {
        if (!$this->paired) {
            return 'Not paired with a hub.';
        }

        return 'Paired with ' . ($this->hubUrl ?? 'a hub') . '.';
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * Quota and bandwidth limits for a user.
 * Mirrors the server's user quota DTO used in GET/PUT /api/v1/admin/users/{id}/quota.
 */
final readonly class UserQuota
{
    public function __construct(
        public int $maxConcurrentStreams,
        public ?int $maxTotalBandwidthKbps = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            maxConcurrentStreams: Coerce::int($data['max_concurrent_streams'] ?? $data['maxConcurrentStreams'] ?? 0),
            maxTotalBandwidthKbps: Coerce::nint($data['max_total_bandwidth_kbps'] ?? $data['maxTotalBandwidthKbps'] ?? null),
        );
    }

    /**
     * @return array{max_concurrent_streams: int, max_total_bandwidth_kbps: int|null}
     */
    public function toArray(): array
    {
        return [
            'max_concurrent_streams' => $this->maxConcurrentStreams,
            'max_total_bandwidth_kbps' => $this->maxTotalBandwidthKbps,
        ];
    }
}
<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin\Parental;

use Phlix\Console\Api\Dto\Coerce;

/**
 * Stream limit settings for a profile.
 * Mirrors the server's StreamLimit DTO used in GET/PUT /api/v1/profiles/{id}/stream-limits.
 */
final readonly class ProfileStreamLimit
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

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

/**
 * The hardware-accelerator introspection result from
 * `GET /api/v1/admin/transcoding/accelerators`. UNLIKE the server-settings
 * endpoints, the transcoding controller returns its payload at the TOP LEVEL
 * with NO `{success, data}` envelope (admin envelopes are per-controller), so
 * the fields are read straight from `$body`.
 *
 * Immutable.
 */
final readonly class TranscodingAccelerators
{
    /**
     * @param list<AcceleratorInfo> $accelerators
     */
    public function __construct(
        public array $accelerators,
        public bool $autoDetected,
        public string $ffmpegVersion,
        public ?string $preferredAccelerator,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $accelerators = [];
        foreach (is_array($data['accelerators'] ?? null) ? $data['accelerators'] : [] as $accel) {
            if (is_array($accel)) {
                $accelerators[] = AcceleratorInfo::fromArray($accel);
            }
        }

        $ffmpegVersion = $data['ffmpeg_version'] ?? 'unknown';

        return new self(
            $accelerators,
            (bool) ($data['auto_detected'] ?? false),
            is_string($ffmpegVersion) ? $ffmpegVersion : 'unknown',
            isset($data['preferred_accelerator']) && is_string($data['preferred_accelerator']) ? $data['preferred_accelerator'] : null,
        );
    }
}

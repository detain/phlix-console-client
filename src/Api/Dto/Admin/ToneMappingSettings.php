<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The HDR tone-mapping settings from `GET /api/v1/admin/transcoding/tone-mapping`.
 * The PUT response carries the same shape. UNLIKE the server-settings endpoints
 * (and LIKE the transcoding accelerators), the tone-mapping controller returns
 * its payload at the TOP LEVEL with NO `{success, data}` envelope (admin
 * envelopes are per-controller), so the fields are read straight from `$body`.
 *
 * Immutable.
 */
final readonly class ToneMappingSettings
{
    public function __construct(
        public string $toneMappingMode,
        public bool $preferHdrOutput,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $mode = $data['tone_mapping_mode'] ?? 'zscale';

        return new self(
            is_string($mode) ? $mode : 'zscale',
            (bool) ($data['prefer_hdr_output'] ?? false),
        );
    }

    /** Valid tone-mapping mode values. */
    public const VALID_MODES = ['none', 'zscale', 'libplacebo'];
}

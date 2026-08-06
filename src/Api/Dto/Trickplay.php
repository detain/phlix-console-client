<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * Trickplay sprite + timeline URLs for a media item's scrubber thumbnail preview.
 *
 * Fetched from `GET /api/v1/media/{id}/trickplay`. Both URLs may be null when
 * trickplay has not been generated for the item or the feature is disabled.
 *
 * @see \Phlix\Console\Api\ApiClient::trickplay()
 */
final readonly class Trickplay
{
    public function __construct(
        /** The sprite-sheet image URL (a grid of preview thumbnails). Null when unavailable. */
        public ?string $spriteUrl,
        /** The timeline JSON URL that maps time offsets to sprite-grid coordinates. Null when unavailable. */
        public ?string $timelineUrl,
    ) {
    }

    /** @param array{sprite_url:?string, timeline_url:?string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['sprite_url'] ?? null) ? $data['sprite_url'] : null,
            is_string($data['timeline_url'] ?? null) ? $data['timeline_url'] : null,
        );
    }
}

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * Trickplay sprite + timeline URLs for a media item's scrubber thumbnail preview.
 *
 * Fetched from `GET /api/v1/media/{id}/trickplay`. Both URLs may be null when
 * trickplay has not been generated for the item or the feature is disabled.
 *
 * The URLs are server-sourced strings and follow the estate DTO ingest law
 * ({@see Coerce}, C22 lineage): sanitized at PARSE, trusted downstream. Their
 * only reader today is {@see \Phlix\Console\Screen\PlayerScreen::isTrickplayAvailable()}
 * (null-checks) and the fetch/cache plumbing — never a terminal sink — but the
 * DTO also round-trips through the on-disk TrickplayCache blob and debug
 * surfaces, so no raw wire bytes may be stored here (M-CONSOLE-33 census).
 * An empty or all-noise URL folds to null, which is exactly "unavailable".
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

    /** @param array{sprite_url?:mixed, timeline_url?:mixed} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Coerce::nstr($data['sprite_url'] ?? null),
            Coerce::nstr($data['timeline_url'] ?? null),
        );
    }
}

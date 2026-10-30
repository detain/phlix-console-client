<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

use Phlix\Console\Api\ServerAuthority;

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
 * R-CONSOLE-09 adds the SECOND fold law: host trust. These are server-chosen
 * URLs destined for sprite/timeline retrieval, so they obey the media-authority
 * law ({@see ServerAuthority}, M-CONSOLE-55 lineage) — NOT the artwork
 * carve-out: trickplay is server-generated content, never a third-party CDN
 * hand-off. An ABSOLUTE URL survives parse only when it shares the configured
 * server's host authority (exact host or subdomain); anything else folds to
 * null — "unavailable" — so a hostile server cannot park foreign URLs in the
 * DTO, the memory tier, or the on-disk cache blob. Hostless (relative) URLs
 * resolve against the configured server by construction and pass untouched.
 * Callers that carry the base URL MUST pass it; the `''` default fail-closes
 * every absolute candidate while leaving relative ones available.
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
    public static function fromArray(array $data, string $baseUrl = ''): self
    {
        return new self(
            self::hostPinned(Coerce::nstr($data['sprite_url'] ?? null), $baseUrl),
            self::hostPinned(Coerce::nstr($data['timeline_url'] ?? null), $baseUrl),
        );
    }

    /**
     * R-CONSOLE-09: the media-authority clamp applied to each parsed URL.
     * null passes through; a URL with no host component (relative to the
     * configured server) is same-origin by construction; an absolute URL with
     * a foreign, malformed, or unattributable host folds to null.
     */
    private static function hostPinned(?string $url, string $baseUrl): ?string
    {
        if ($url === null) {
            return null;
        }

        if (ServerAuthority::hostOf($url) === null) {
            return $url;
        }

        return ServerAuthority::trusted($baseUrl, $url) ? $url : null;
    }
}

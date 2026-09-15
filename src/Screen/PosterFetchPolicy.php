<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use SugarCraft\Gallery\PosterCard;

/**
 * The phlix-side transport policy for filling a {@see \SugarCraft\Gallery\PosterGrid}
 * through sugar-gallery's {@see \SugarCraft\Gallery\PosterGrid::indicesNeedingPoster()}
 * seam. The seam deliberately encodes no transport knowledge; this trait is the
 * application half, passed to the grid as the `$isFillable` closure: a cell is
 * fillable only when its (possibly relative) poster URL resolves — via the host
 * screen's own `resolveUrl()` — to an absolute http/https target. Cells failing
 * the guard are never queued; they keep their skeleton.
 *
 * Requires the using class to define:
 *     private function resolveUrl(string $url): string
 * (resolve relative → server-base absolute; absolute/empty pass through).
 */
trait PosterFetchPolicy
{
    /** Seam fillability predicate: queue only cells whose poster URL is fetchable. */
    private function isFillablePoster(PosterCard $card): bool
    {
        return $this->fetchablePosterUrl($card) !== null;
    }

    /**
     * The card's poster URL resolved against the server base (absolute/signed URLs
     * pass through) — or null for a missing, empty, malformed or non-http(s)
     * target, which is treated the same as no poster at all.
     */
    private function fetchablePosterUrl(PosterCard $card): ?string
    {
        if ($card->posterUrl === null || $card->posterUrl === '') {
            return null;
        }

        return $this->fetchableUrl($card->posterUrl);
    }

    /**
     * The transport policy (app-owned, never lib-side): resolve a possibly
     * relative poster URL against the server base (absolute/signed URLs pass
     * through) and require an http/https target. Empty, malformed or non-http(s)
     * URLs return null — the cell keeps its skeleton/placeholder and is never
     * queued. A raw relative URL MUST be resolved before the scheme check, or
     * schemeless relatives would be wrongly dropped.
     */
    private function fetchableUrl(string $url): ?string
    {
        $resolved = $this->resolveUrl($url);
        // parse_url returns false for malformed URLs and null for URLs with no scheme.
        $scheme = parse_url($resolved, PHP_URL_SCHEME);

        return is_string($scheme) && in_array($scheme, ['http', 'https'], true) ? $resolved : null;
    }
}

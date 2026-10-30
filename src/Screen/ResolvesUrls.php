<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\ServerAuthority;

/**
 * The clone-safe artwork URL join 11 screens carried byte-identically (M-CONSOLE-37).
 *
 * Was 11 raw-identical private copies across src/Screen/. A screen `use`s this trait
 * when it keeps the server root in a private string $baseUrl field and resolves
 * artwork/thumb hrefs against it — the body (including the inline comment) is verbatim
 * from the copies it replaces; the URL-join edge cases the estate kept re-fixing now
 * live in exactly one place, pinned by
 * {@see \Phlix\Console\Tests\Unit\Screen\ScreenConcernSingleSourceTest}.
 */
trait ResolvesUrls
{
    /**
     * Resolves a relative URL to an absolute URL using the configured baseUrl.
     * Handles empty strings and already-absolute http/https URLs as pass-through.
     * Relative URLs (e.g., /api/v1/artwork/...) are resolved against baseUrl.
     */
    private function resolveUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url; // empty, or already absolute (signed URLs are absolute)
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * resolveUrl() PLUS the media-authority gate [M-CONSOLE-55]: a server-
     * supplied URL destined for a playback/download sink is honoured only when
     * hosted at (or beneath) the configured server's host. A foreign host
     * resolves to '' — every caller already treats an empty source URL as its
     * failure signal ("no playable source", the transcode arm, the error
     * toast), so the refusal lands on existing UX with zero new states.
     * Artwork keeps using plain resolveUrl()/PosterFetchPolicy on purpose:
     * cross-host CDN art is absolute by server design and display-only.
     */
    private function resolveMediaUrl(string $url): string
    {
        $resolved = $this->resolveUrl($url);
        if ($resolved === '') {
            return '';
        }

        // Only http(s) targets carry the arbitrary-host fetch risk this gate
        // exists for; a resolved local path (dev/selftest clips) has no host
        // to pin.
        if (preg_match('#^https?://#i', $resolved) !== 1) {
            return $resolved;
        }

        return ServerAuthority::trusted($this->baseUrl, $resolved) ? $resolved : '';
    }
}

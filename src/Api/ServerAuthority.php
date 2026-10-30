<?php

declare(strict_types=1);

namespace Phlix\Console\Api;

/**
 * The media-authority law [review console__secaudit m4, M-CONSOLE-55].
 *
 * The server hands the client signed, ABSOLUTE media URLs (stream/transcode/
 * rendition/cast/download targets) — and the client hands those URLs to
 * ffplay/ffprobe, the cast bridge, and the file downloader. A compromised or
 * hostile server could otherwise point any of those sinks at an arbitrary
 * host: the client (and, for casting, a living-room device) becomes the
 * fetcher of someone else's URL.
 *
 * Law: a URL destined for a PLAYBACK/DOWNLOAD sink is honoured only when its
 * host is the configured server itself or a subdomain beneath it
 * (`phlix.example.com` authorises `cdn.phlix.example.com`, which is exactly
 * how signed-CDN offloads are deployed). Artwork/poster URLs are the
 * documented carve-out: the server hands absolute TMDB/CDN links by design,
 * they render as display-only rasters through PosterFetchPolicy, and they are
 * never passed to proc_open or the downloader.
 *
 * Match is on HOST only (ports ignored — same-host offloads legitimately run
 * on their own port); hosts compare case-insensitively; a URL without a
 * parseable host is never trusted.
 */
final class ServerAuthority
{
    /** Lowercased host of $url, or null when it has none. */
    public static function hostOf(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    /**
     * Does $candidate share (or sit beneath) the host authority of $baseUrl?
     * An unparseable base or candidate is refused: fail closed.
     */
    public static function trusted(string $baseUrl, string $candidate): bool
    {
        $baseHost = self::hostOf($baseUrl);
        $candHost = self::hostOf($candidate);

        if ($baseHost === null || $candHost === null) {
            return false;
        }

        return $candHost === $baseHost || str_ends_with($candHost, '.' . $baseHost);
    }
}

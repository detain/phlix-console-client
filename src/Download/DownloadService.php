<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Download;

use Phlix\Console\Api\ServerAuthority;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;

use function React\Promise\reject;

/**
 * Service for downloading media files from signed URLs.
 *
 * Honours the PHLIX_DOWNLOAD_DIR environment variable, defaulting to ~/Downloads.
 * Cleans up partial downloads on failure.
 */
final class DownloadService
{
    /** Environment variable name for the download directory. */
    public const ENV_DOWNLOAD_DIR = 'PHLIX_DOWNLOAD_DIR';

    /** Default download directory name when env var is not set. */
    private const DEFAULT_DOWNLOAD_DIR = 'Downloads';

    /**
     * Hard ceiling on a single download [review console__secaudit m5,
     * M-CONSOLE-54]. Without it a hostile (or merely broken) server can stream
     * unbounded bytes into the user's home directory. 64 GiB is far above any
     * realistic Phlix asset — the limit only ever fires on something that has
     * no business being that large — while still bounding the damage a
     * misbehaving response can do. Callers with a different budget pass
     * {@see $maxBytes}.
     */
    public const MAX_DOWNLOAD_BYTES = 68_719_476_736; // 64 GiB

    /** @var Browser */
    private readonly Browser $browser;

    /** @var string */
    private readonly string $downloadDir;

    /** Per-download byte ceiling enforced on the declared and streamed size. */
    private readonly int $maxBytes;

    /**
     * The mode the service gives the download directory WHEN IT CREATES IT, and
     * the mode every file it downloads gets. 0700/0600 keeps the user's media —
     * and the signed-URL filenames that ship alongside — private to the account
     * instead of world-readable [review console__secaudit m5, M-CONSOLE-54].
     * A directory that already exists (e.g. the desktop ~/Downloads) belongs to
     * the user and is deliberately NOT re-chmodded; the files this client writes
     * still land 0600.
     */
    private const PRIVATE_DIR_MODE = 0o700;
    private const PRIVATE_FILE_MODE = 0o600;

    /**
     * The filename shape this client will write: word chars, dots, spaces,
     * parentheses, square brackets and hyphens, at most 120 bytes, and never a
     * leading dot (dotfiles hide themselves from the user's own file picker).
     * Anything else — path separators, traversal fragments, control bytes,
     * absurd lengths — falls back to the media-id name.
     *
     * The review's literal `/^[\w. ()\[-]{1,120}$/` shipped broken: the
     * unescaped `]` terminated the class early (measured: it matches only one
     * class character plus a run of hyphens). Both brackets are escaped here —
     * the intent the review spelled out in prose.
     */
    private const FILENAME_PATTERN = '/^[\w. ()\[\]-]{1,120}$/';

    /** The media-id shape the fallback name trusts. */
    private const MEDIA_ID_PATTERN = '/^[\w-]{1,64}$/';

    /**
     * Host authority for media URLs this service may fetch [M-CONSOLE-55];
     * null = unconstrained (construction without a server base — tests and
     * any future non-server flows). The production wiring passes the server
     * base and every signed download URL is checked against it.
     */
    private readonly ?string $serverBase;

    /**
     * @param Browser|null    $browser     injected HTTP client (tests)
     * @param string|null     $serverBase  host authority media URLs may come from
     * @param int             $maxBytes    per-download ceiling, see {@see MAX_DOWNLOAD_BYTES}
     */
    public function __construct(?Browser $browser = null, ?string $serverBase = null, int $maxBytes = self::MAX_DOWNLOAD_BYTES)
    {
        $this->browser = $browser ?? new Browser();
        $this->downloadDir = $this->resolveDownloadDir();
        $this->serverBase = $serverBase;
        $this->maxBytes = max(1, $maxBytes);
    }

    /**
     * Get the configured download directory.
     */
    public function downloadDir(): string
    {
        return $this->downloadDir;
    }

    /**
     * Download a file from a signed URL.
     *
     * @param string $signedUrl The signed download URL
     * @param string $mediaId The media item ID (used for filename if not provided)
     * @param callable|null $_onProgress Called with (bytesReceived, totalBytes) for progress updates (reserved for future use)
     * @return PromiseInterface<DownloadResult> Resolves with download result on success
     */
    /**
     * @return PromiseInterface<DownloadResult>
     */
    public function download(
        string $signedUrl,
        string $mediaId,
        ?callable $_onProgress = null,
    ): PromiseInterface {
        // Media-authority gate [review console__secaudit m4, M-CONSOLE-55]:
        // a signed URL from a compromised server must not turn this client
        // into a fetcher for an arbitrary host. Checked before any filesystem
        // work so a refused URL never touches disk.
        if ($this->serverBase !== null && !ServerAuthority::trusted($this->serverBase, $signedUrl)) {
            /** @var PromiseInterface<DownloadResult> */
            return reject(new DownloadException(
                'Download refused: the signed URL points outside the configured server.',
                0,
            ));
        }

        // Ensure download directory exists — created PRIVATE (0700, chmod
        // defeats the process umask so the mode is the declared one) [m5].
        if (!is_dir($this->downloadDir)) {
            $created = @mkdir($this->downloadDir, self::PRIVATE_DIR_MODE, true);
            if (!$created && !is_dir($this->downloadDir)) {
                /** @var PromiseInterface<DownloadResult> */
                return reject(new DownloadException(
                    "Cannot create download directory: {$this->downloadDir}",
                    0,
                ));
            }
            if ($created) {
                @chmod($this->downloadDir, self::PRIVATE_DIR_MODE);
            }
        }

        // Extract filename from URL and determine final path
        $filename = $this->extractFilename($signedUrl, $mediaId);
        $filepath = $this->downloadDir . '/' . $filename;
        $tmpFilepath = $filepath . '.tmp.' . bin2hex(random_bytes(8));

        /** @var Deferred<DownloadResult> $deferred */
        $deferred = new Deferred();

        // Use ReactPHP browser to make the streaming request
        $this->browser->requestStreaming('GET', $signedUrl)
            ->then(
                function ($response) use ($filepath, $filename, $deferred): void {
                    // Get content length if available
                    $totalBytes = 0;
                    $contentLength = $response->getHeaderLine('Content-Length');
                    if (is_numeric($contentLength)) {
                        $totalBytes = (int) $contentLength;
                    }

                    // Declared-size guard [review console__secaudit m5, M-CONSOLE-54]:
                    // a server that announces more than the ceiling is refused
                    // before it opens a single file — no head start on the disk.
                    if ($totalBytes > $this->maxBytes) {
                        $deferred->reject(new DownloadException(
                            "Download refused: it declared {$totalBytes} bytes, above the {$this->maxBytes} byte limit.",
                            0,
                        ));
                        return;
                    }

                    // Adjust filepath for content type if needed
                    $contentType = $response->getHeaderLine('Content-Type');
                    $finalFilepath = $this->ensureExtension($filepath, $contentType);
                    $finalTmpFilepath = $finalFilepath . '.tmp.' . bin2hex(random_bytes(8));

                    $maxBytes = $this->maxBytes;
                    $receivedBytes = 0;
                    $overflowed = false;
                    $handle = @fopen($finalTmpFilepath, 'wb');

                    if ($handle === false) {
                        $deferred->reject(new DownloadException(
                            "Cannot open temporary file: {$finalTmpFilepath}",
                            0,
                        ));
                        return;
                    }

                    // Downloads are written private regardless of the process
                    // umask [m5]. (chmod-by-path, not fchmod: this estate's PHP
                    // build has no fchmod — measured function_exists false.)
                    @chmod($finalTmpFilepath, self::PRIVATE_FILE_MODE);

                    // The body from requestStreaming is a ReadableStreamInterface
                    $body = $response->getBody();

                    // Check if it's a React stream with 'data' event
                    if (method_exists($body, 'on')) {
                        // React stream - use 'data' event for streaming
                        $body->on('data', static function ($chunk) use ($handle, $finalTmpFilepath, $deferred, &$receivedBytes, &$overflowed, $maxBytes): void {
                            if ($overflowed) {
                                return; // Already past the cap: discard, settled once.
                            }
                            $written = fwrite($handle, (string) $chunk);
                            if ($written !== false) {
                                $receivedBytes += $written;
                            }
                            // Streamed-size guard [m5]: the cap is enforced on
                            // bytes actually written, so an absent or lying
                            // Content-Length cannot smuggle an unbounded body.
                            if ($receivedBytes > $maxBytes) {
                                $overflowed = true;
                                fclose($handle);
                                @unlink($finalTmpFilepath);
                                $deferred->reject(new DownloadException(
                                    "Download refused: it streamed past the {$maxBytes} byte limit.",
                                    0,
                                ));
                            }
                        });

                        $body->on('end', static function () use ($handle, $finalTmpFilepath, $finalFilepath, $filename, $deferred, &$receivedBytes, &$overflowed, $totalBytes): void {
                            if ($overflowed) {
                                return; // The data handler already cleaned up and rejected.
                            }
                            fclose($handle);
                            if (@rename($finalTmpFilepath, $finalFilepath)) {
                                $deferred->resolve(new DownloadResult(
                                    url: $finalFilepath,
                                    filename: $filename,
                                    size: $receivedBytes,
                                    totalBytes: $totalBytes,
                                ));
                            } else {
                                @unlink($finalTmpFilepath);
                                $deferred->reject(new DownloadException(
                                    "Failed to move downloaded file to: {$finalFilepath}",
                                    0,
                                ));
                            }
                        });

                        $body->on('error', static function (\Throwable $error) use ($handle, $finalTmpFilepath, $deferred, &$overflowed): void {
                            if ($overflowed) {
                                return; // Cap breach already settled this download.
                            }
                            fclose($handle);
                            @unlink($finalTmpFilepath);
                            $deferred->reject(new DownloadException(
                                "Download stream error: " . $error->getMessage(),
                                0,
                                $error,
                            ));
                        });
                    } else {
                        // Not a React stream - close handle and fall back to synchronous download
                        fclose($handle);
                        @unlink($finalTmpFilepath);
                        // Note: for non-streaming responses, we'd need a different approach
                        $deferred->reject(new DownloadException(
                            "Unexpected response type - streaming not supported",
                            0,
                        ));
                    }
                },
                function (\Throwable $error) use ($deferred, $tmpFilepath): void {
                    @unlink($tmpFilepath);
                    $deferred->reject(new DownloadException(
                        "Download request failed: " . $error->getMessage(),
                        0,
                        $error,
                    ));
                },
            );

        return $deferred->promise();
    }

    /**
     * Resolve the download directory from environment or default.
     */
    private function resolveDownloadDir(): string
    {
        // getenv() over the superglobals: one true process-environment read
        // (putenv lands here; $_ENV only when variables_order says so).
        $dir = getenv(self::ENV_DOWNLOAD_DIR);

        if (is_string($dir) && $dir !== '') {
            return $this->expandPath($dir);
        }

        // Default to ~/Downloads
        /** @var string $home */
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        if ($home === '') {
            /** @var string $home */
            $home = sys_get_temp_dir();
        }

        $defaultDir = rtrim($home, '/') . '/' . self::DEFAULT_DOWNLOAD_DIR;

        return $defaultDir;
    }

    /**
     * Expand ~ and environment variables in a path.
     */
    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~')) {
            /** @var string $home */
            $home = getenv('HOME') ?: '';
            if ($home !== '') {
                $path = $home . substr($path, 1);
            }
        }

        // Expand any ${VAR} or $VAR patterns
        $result = preg_replace_callback(
            '/\$(\w+|\{([^}]+)\})/',
            static function (array $matches): string {
                $name = $matches[2] ?? $matches[1];
                $value = getenv($name);

                return is_string($value) ? $value : $matches[0];
            },
            $path,
        );

        // preg_replace_callback returns string|null; return original path on null
        return is_string($result) ? $result : $path;
    }

    /**
     * Extract a filename from the signed URL's Content-Disposition header or URL path.
     *
     * Trust-boundary note (M-CONSOLE-33 census): $signedUrl has exactly one
     * producer — {@see \Phlix\Console\Api\ApiClient::downloadMedia()} parses
     * the wire `url` through {@see \Phlix\Console\Api\Dto\Coerce::str()}, i.e.
     * Sanitize::untrustedForDisplay at the DTO boundary. parse_url/basename of
     * sanitized text cannot reconstitute an ESC sequence, so the filename that
     * reaches the DetailScreen toast and the on-disk path is terminal-inert by
     * construction; $mediaId is likewise Coerce-sanitized. (No OSC-8 `file://`
     * hyperlink producer exists in this repo — grep ']8;' is empty.)
     */
    private function extractFilename(string $signedUrl, string $mediaId): string
    {
        // Try to extract from URL path
        $parsed = parse_url($signedUrl);
        if (is_array($parsed) && isset($parsed['path'])) {
            /** @var mixed $pathPart */
            $pathPart = $parsed['path'];
            if (is_string($pathPart) && $pathPart !== '') {
                $basename = basename($pathPart);
                // The name must survive the shape law [review console__secaudit
                // m5, M-CONSOLE-54]: a leading dot hides the download from the
                // user's own file picker, and any character outside the
                // conservative printable class (path separators, traversal
                // fragments, control bytes, absurd length) forfeits the
                // server-supplied name. An extension is still required, as before.
                if (
                    $basename !== ''
                    && !str_starts_with($basename, '.')
                    && str_contains($basename, '.')
                    && preg_match(self::FILENAME_PATTERN, $basename) === 1
                ) {
                    return $basename;
                }
            }
        }

        // Default filename based on media ID — the ID embeds into the name, so
        // it is trusted only to the same extent: anything outside the ID shape
        // falls back to a constant rather than through the name.
        if (preg_match(self::MEDIA_ID_PATTERN, $mediaId) === 1) {
            return "phlix_{$mediaId}.mp4";
        }

        return 'phlix-download.mp4';
    }

    /**
     * Ensure the file has a proper extension based on content type.
     */
    private function ensureExtension(string $filepath, string $contentType): string
    {
        $extension = match (true) {
            str_contains($contentType, 'video/mp4') => '.mp4',
            str_contains($contentType, 'video/webm') => '.webm',
            str_contains($contentType, 'video/ogg') => '.ogv',
            str_contains($contentType, 'video/x-matroska') => '.mkv',
            str_contains($contentType, 'audio/mpeg') => '.mp3',
            str_contains($contentType, 'audio/ogg') => '.ogg',
            str_contains($contentType, 'audio/flac') => '.flac',
            str_contains($contentType, 'audio/wav') => '.wav',
            str_contains($contentType, 'application/pdf') => '.pdf',
            str_contains($contentType, 'application/epub') => '.epub',
            str_contains($contentType, 'application/mobi') => '.mobi',
            default => '',
        };

        if ($extension === '') {
            return $filepath;
        }

        // If filepath already has an extension, don't change it
        if (pathinfo($filepath, PATHINFO_EXTENSION) !== '') {
            return $filepath;
        }

        return $filepath . $extension;
    }
}

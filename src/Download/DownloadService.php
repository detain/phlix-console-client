<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Download;

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

    /** @var Browser */
    private readonly Browser $browser;

    /** @var string */
    private readonly string $downloadDir;

    public function __construct(?Browser $browser = null)
    {
        $this->browser = $browser ?? new Browser();
        $this->downloadDir = $this->resolveDownloadDir();
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
        // Ensure download directory exists
        if (!is_dir($this->downloadDir) && !@mkdir($this->downloadDir, 0o755, true) && !is_dir($this->downloadDir)) {
            /** @var PromiseInterface<DownloadResult> */
            return reject(new DownloadException(
                "Cannot create download directory: {$this->downloadDir}",
                0,
            ));
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

                    // Adjust filepath for content type if needed
                    $contentType = $response->getHeaderLine('Content-Type');
                    $finalFilepath = $this->ensureExtension($filepath, $contentType);
                    $finalTmpFilepath = $finalFilepath . '.tmp.' . bin2hex(random_bytes(8));

                    $receivedBytes = 0;
                    $handle = @fopen($finalTmpFilepath, 'wb');

                    if ($handle === false) {
                        $deferred->reject(new DownloadException(
                            "Cannot open temporary file: {$finalTmpFilepath}",
                            0,
                        ));
                        return;
                    }

                    // The body from requestStreaming is a ReadableStreamInterface
                    $body = $response->getBody();

                    // Check if it's a React stream with 'data' event
                    if (method_exists($body, 'on')) {
                        // React stream - use 'data' event for streaming
                        $body->on('data', static function ($chunk) use ($handle, &$receivedBytes): void {
                            $written = fwrite($handle, (string) $chunk);
                            if ($written !== false) {
                                $receivedBytes += $written;
                            }
                        });

                        $body->on('end', static function () use ($handle, $finalTmpFilepath, $finalFilepath, $filename, $deferred, &$receivedBytes, $totalBytes): void {
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

                        $body->on('error', static function (\Throwable $error) use ($handle, $finalTmpFilepath, $deferred): void {
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
        $dir = $_ENV[self::ENV_DOWNLOAD_DIR] ?? $_SERVER[self::ENV_DOWNLOAD_DIR] ?? null;

        if (is_string($dir) && $dir !== '') {
            return $this->expandPath($dir);
        }

        // Default to ~/Downloads
        /** @var string $home */
        $home = $_ENV['HOME'] ?? $_SERVER['HOME'] ?? $_ENV['USERPROFILE'] ?? '';
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
            $home = $_ENV['HOME'] ?? $_SERVER['HOME'] ?? '';
            if ($home !== '') {
                $path = $home . substr($path, 1);
            }
        }

        // Expand any ${VAR} or $VAR patterns
        $result = preg_replace_callback(
            '/\$(\w+|\{([^}]+)\})/',
            static function (array $matches): string {
                $name = $matches[2] ?? $matches[1];
                $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

                return is_string($value) ? $value : $matches[0];
            },
            $path,
        );

        // preg_replace_callback returns string|null; return original path on null
        return is_string($result) ? $result : $path;
    }

    /**
     * Extract a filename from the signed URL's Content-Disposition header or URL path.
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
                if ($basename !== '' && str_contains($basename, '.')) {
                    return $basename;
                }
            }
        }

        // Default filename based on media ID
        return "phlix_{$mediaId}.mp4";
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

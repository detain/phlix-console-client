<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Download progress update.
 */
final readonly class DownloadProgressMsg implements Msg
{
    public function __construct(
        /** The media item id */
        public string $mediaId,
        /** Bytes downloaded so far */
        public int $bytesReceived,
        /** Total bytes to download (0 if unknown) */
        public int $totalBytes,
    ) {
    }

    /**
     * Get the progress as a percentage (0-100).
     */
    public function percent(): int
    {
        if ($this->totalBytes <= 0) {
            return 0;
        }

        return (int) min(100, round(($this->bytesReceived / $this->totalBytes) * 100));
    }

    /**
     * Get human-readable progress string.
     */
    public function progressString(): string
    {
        if ($this->totalBytes <= 0) {
            return $this->formatBytes($this->bytesReceived) . ' downloaded';
        }

        return $this->formatBytes($this->bytesReceived) . ' / ' . $this->formatBytes($this->totalBytes);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }

        return sprintf('%.2f GB', $bytes / (1024 * 1024 * 1024));
    }
}

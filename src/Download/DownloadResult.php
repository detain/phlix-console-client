<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Download;

/**
 * Result of a successful download operation.
 */
final readonly class DownloadResult
{
    public function __construct(
        /** The full path where the file was saved. */
        public string $url,
        /** The filename of the downloaded file. */
        public string $filename,
        /** The size of the downloaded file in bytes. */
        public int $size,
        /** The total expected bytes (0 if unknown). */
        public int $totalBytes,
    ) {
    }

    /**
     * Get human-readable size string.
     */
    public function sizeFormatted(): string
    {
        if ($this->size < 1024) {
            return "{$this->size} B";
        }
        if ($this->size < 1024 * 1024) {
            return sprintf('%.1f KB', $this->size / 1024);
        }
        if ($this->size < 1024 * 1024 * 1024) {
            return sprintf('%.1f MB', $this->size / (1024 * 1024));
        }

        return sprintf('%.2f GB', $this->size / (1024 * 1024 * 1024));
    }
}

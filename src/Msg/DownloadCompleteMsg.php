<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Download completed successfully.
 */
final readonly class DownloadCompleteMsg implements Msg
{
    public function __construct(
        /** The media item id */
        public string $mediaId,
        /** The full path where the file was saved */
        public string $filepath,
        /** The filename */
        public string $filename,
        /** File size in bytes */
        public int $size,
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

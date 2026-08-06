<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A signed download URL has been resolved for a media item.
 */
final readonly class DownloadAvailableMsg implements Msg
{
    /**
     * @param string $mediaId   The media item id
     * @param string $url        The signed download URL
     * @param string $filename   Suggested filename for the download
     * @param int    $size       File size in bytes
     * @param string $contentType MIME type of the file
     */
    public function __construct(
        public string $mediaId,
        public string $url,
        public string $filename,
        public int $size,
        public string $contentType,
    ) {
    }
}

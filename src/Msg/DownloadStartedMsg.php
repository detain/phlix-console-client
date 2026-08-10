<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Download has started (signed URL resolved, actual download beginning).
 */
final readonly class DownloadStartedMsg implements Msg
{
    public function __construct(
        /** The media item id */
        public string $mediaId,
        /** The destination directory */
        public string $downloadDir,
        /** The filename being saved as */
        public string $filename,
    ) {
    }
}

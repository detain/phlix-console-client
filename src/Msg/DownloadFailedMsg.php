<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A download failed (item not found, over-cap, file missing on disk, etc.).
 */
final readonly class DownloadFailedMsg implements Msg
{
    public function __construct(
        public string $mediaId,
        public string $reason,
    ) {
    }
}

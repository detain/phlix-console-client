<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Signal fired after a subtitle is successfully downloaded, so listening
 * screens (e.g. PlayerScreen) can refresh their available subtitle track list.
 */
final readonly class SubtitleDownloadedMsg implements Msg
{
    public function __construct(
        /** The media item the subtitle was downloaded for. */
        public string $mediaId,
    ) {
    }
}

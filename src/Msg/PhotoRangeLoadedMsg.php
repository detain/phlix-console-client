<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Store\PhotoRange;
use SugarCraft\Core\Msg;

/**
 * The paged photo albums for a library resolved — the PhotosScreen uses
 * {@see PhotosStore::ensureRange()} to fetch only the visible window plus
 * overscan, so even a large library scrolls smoothly.
 */
final readonly class PhotoRangeLoadedMsg implements Msg
{
    public function __construct(
        public PhotoRange $range,
        public int $generation,
    ) {
    }
}
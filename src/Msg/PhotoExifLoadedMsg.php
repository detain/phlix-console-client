<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\PhotoExif;
use SugarCraft\Core\Msg;

/**
 * The EXIF metadata for the photo currently shown in the
 * {@see \Phlix\Console\Screen\PhotoViewerScreen} resolved. `$exif` is null when
 * the detail carries none (or a non-auth fetch error degraded to null), so the
 * panel shows "No EXIF data" rather than hanging on "Loading EXIF…".
 *
 * Carries the same load `$generation` as {@see PhotoImageLoadedMsg}: an EXIF
 * result for a superseded photo is recognised as stale and dropped.
 */
final readonly class PhotoExifLoadedMsg implements Msg
{
    public function __construct(
        public int $generation,
        public ?PhotoExif $exif,
    ) {
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The fullscreen image for the photo currently shown in the
 * {@see \Phlix\Console\Screen\PhotoViewerScreen} finished rendering to ANSI.
 *
 * Carries the load `$generation` it was armed under: navigation, the EXIF
 * toggle (the image reloads at a narrower width) and a resize all bump the
 * generation, so an image resolved for a superseded photo/size is recognised as
 * stale and dropped rather than painted over the current one.
 */
final readonly class PhotoImageLoadedMsg implements Msg
{
    public function __construct(
        public int $generation,
        public string $ansi,
    ) {
    }
}

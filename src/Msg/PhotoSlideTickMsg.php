<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The slideshow interval elapsed in the
 * {@see \Phlix\Console\Screen\PhotoViewerScreen}: advance to the next photo and
 * re-arm the next tick.
 *
 * Carries the slideshow `$epoch` it was armed under — a SEPARATE generation from
 * the image/EXIF load generation. Toggling the slideshow off, or any manual
 * navigation while it runs, bumps the epoch, so a tick left over from a
 * superseded countdown is recognised as stale and dropped — preventing two
 * slideshow chains from running at once (which would skip photos / double the
 * advance rate). The chain only re-arms while THIS screen processes a live tick,
 * so a tick that arrives after the screen is popped routes to the new top screen
 * and dies there.
 */
final readonly class PhotoSlideTickMsg implements Msg
{
    public function __construct(public int $epoch = 0)
    {
    }
}

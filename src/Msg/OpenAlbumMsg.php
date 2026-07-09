<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Album;
use SugarCraft\Core\Msg;

/**
 * Open an album's track list — the App pushes an AlbumScreen onto the stack.
 * Carries the whole {@see Album} (it already holds its tracks, so the screen
 * needs no further fetch).
 */
final readonly class OpenAlbumMsg implements Msg
{
    public function __construct(
        public Album $album,
    ) {
    }
}

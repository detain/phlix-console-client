<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open the music library filtered to a specific artist — the App pushes a
 * MusicScreen (filtered) onto the stack when an artist is selected from the
 * MusicArtistsScreen.
 */
final readonly class OpenMusicForArtistMsg implements Msg
{
    public function __construct(
        public string $artistName,
    ) {
    }
}

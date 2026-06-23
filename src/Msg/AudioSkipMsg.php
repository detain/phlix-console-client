<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Move the App's active music session to the track $delta away from the one
 * playing: +1 for the next track (AlbumScreen `n`), -1 for the previous
 * (AlbumScreen `p`). A no-op when nothing is playing or the move runs off an
 * end of the album.
 */
final readonly class AudioSkipMsg implements Msg
{
    public function __construct(public int $delta)
    {
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Resolving or playing a track failed (no stream URL, or a non-auth fetch
 * error). The AlbumScreen surfaces an error toast and leaves any current
 * playback untouched — a failed start never interrupts what is already playing.
 */
final readonly class AudioFailedMsg implements Msg
{
    public function __construct(
        public ?string $reason = null,
    ) {
    }
}

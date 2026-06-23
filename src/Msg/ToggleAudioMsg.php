<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Pause or resume the App's active music session (the persistent
 * {@see \Phlix\Console\Audio\NowPlaying}). Emitted by the AlbumScreen (Space) and
 * by the command palette's Pause/Resume action — a universal control that works
 * from any screen, since the audio now lives on the App, not the album.
 */
final readonly class ToggleAudioMsg implements Msg
{
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Pause or resume the App's active playback session (the persistent
 * {@see \Phlix\Console\Audio\NowPlayingSession} — music OR audiobook). Emitted by
 * the AlbumScreen + AudiobookDetailScreen (Space) and by the command palette's
 * Pause/Resume action — a universal control that works from any screen, since the
 * audio now lives on the App, not the screen.
 */
final readonly class ToggleAudioMsg implements Msg
{
}

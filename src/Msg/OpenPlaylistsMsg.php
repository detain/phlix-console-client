<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open the playlists screen.
 * The App pushes a PlaylistsScreen onto the stack.
 */
final readonly class OpenPlaylistsMsg implements Msg
{
}

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Command-palette action: quit the app (tears down a Teardownable top screen,
 * like Ctrl-C, so no ffmpeg/ffplay subprocess leaks).
 */
final readonly class RequestQuitMsg implements Msg
{
}

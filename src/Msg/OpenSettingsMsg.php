<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Open the Settings screen — the App pushes a SettingsScreen onto the stack. */
final readonly class OpenSettingsMsg implements Msg
{
}

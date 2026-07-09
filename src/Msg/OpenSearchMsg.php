<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Open the global search screen (from the `/` key on a non-text screen, or the
 * command palette's "Search" action).
 */
final readonly class OpenSearchMsg implements Msg
{
}

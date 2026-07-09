<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Command-palette action: log out (clear the stored token and return to login).
 */
final readonly class RequestLogoutMsg implements Msg
{
}

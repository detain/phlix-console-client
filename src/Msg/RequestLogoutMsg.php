<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Command-palette action: log out (clear the stored token and return to login).
 */
final readonly class RequestLogoutMsg implements Msg
{
}

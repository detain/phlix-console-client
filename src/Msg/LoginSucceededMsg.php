<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\AuthUser;
use SugarCraft\Core\Msg;

/** Login (or boot restore) produced an authenticated user. */
final readonly class LoginSucceededMsg implements Msg
{
    public function __construct(
        public AuthUser $user,
    ) {
    }
}

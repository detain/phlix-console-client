<?php

declare(strict_types=1);

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

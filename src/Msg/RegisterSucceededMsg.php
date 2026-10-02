<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\AuthUser;
use SugarCraft\Core\Msg;

/** Registration succeeded and the new user is now authenticated. */
final readonly class RegisterSucceededMsg implements Msg
{
    public function __construct(
        public AuthUser $user,
    ) {
    }
}

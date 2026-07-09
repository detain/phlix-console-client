<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api;

use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Config\TokenBundle;

/**
 * The outcome of a successful login: the authenticated user plus the issued
 * tokens. Immutable.
 */
final readonly class AuthResult
{
    public function __construct(
        public AuthUser $user,
        public TokenBundle $tokens,
    ) {
    }
}

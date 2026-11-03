<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** A login attempt failed; carries a human-readable reason for the form. */
final readonly class LoginFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
        // The typed username travels with the failure so the rebuilt login
        // form can pre-fill it — only the password is thrown away with the
        // failed attempt [review console m49]. Client-internal msg: nothing
        // of this reaches the wire.
        public string $username = '',
    ) {
    }
}

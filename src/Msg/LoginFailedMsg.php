<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** A login attempt failed; carries a human-readable reason for the form. */
final readonly class LoginFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

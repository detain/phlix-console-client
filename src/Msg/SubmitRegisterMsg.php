<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The registration form was submitted with new account details. */
final readonly class SubmitRegisterMsg implements Msg
{
    public function __construct(
        public string $username,
        public string $email,
        public string $password,
    ) {
    }
}

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The login form was submitted with credentials. */
final readonly class SubmitLoginMsg implements Msg
{
    public function __construct(
        public string $username,
        public string $password,
    ) {
    }
}

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Registration failed with a user-friendly error message. */
final readonly class RegisterFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

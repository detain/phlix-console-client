<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** A library action failed — the screen toasts the server `$message`, list unchanged. */
final readonly class AdminLibraryActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

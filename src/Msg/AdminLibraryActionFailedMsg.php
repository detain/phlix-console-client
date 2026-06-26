<?php

declare(strict_types=1);

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

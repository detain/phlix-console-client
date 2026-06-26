<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A library action (scan / rescan / match-metadata) was queued — the screen
 * toasts the server `$message` and immediately fetches scan-status (starting the
 * live poll).
 */
final readonly class AdminLibraryActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A Live-TV action (scan / toggle-enabled / refresh-guide / delete) succeeded —
 * the AdminLiveTvScreen toasts this message and refetches the active section.
 */
final readonly class AdminLiveTvActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

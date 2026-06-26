<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A plugin action (enable / disable / uninstall / install) succeeded. Carries a
 * ready-to-toast success message; the AdminPluginsScreen toasts it and refetches
 * the list.
 */
final readonly class AdminPluginActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

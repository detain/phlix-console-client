<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Loading the plugin detail failed. Carries a friendly message the
 * AdminPluginDetailScreen shows in its error state (with an `r` retry).
 */
final readonly class AdminPluginDetailFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

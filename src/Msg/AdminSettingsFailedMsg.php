<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Loading the server settings failed. Carries a friendly message the
 * AdminSettingsScreen shows in its error state (with an `r` retry).
 */
final readonly class AdminSettingsFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

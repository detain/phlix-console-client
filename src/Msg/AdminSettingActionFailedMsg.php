<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A setting update (PUT) failed. Carries the server `error` (e.g.
 * "Validation failed") for the AdminSettingsScreen to toast; the list is left
 * unchanged.
 */
final readonly class AdminSettingActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

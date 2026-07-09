<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A setting update (PUT) succeeded. Carries the server `message` to toast; the
 * AdminSettingsScreen toasts it and refetches via GET (the PUT response carries
 * no `types`).
 */
final readonly class AdminSettingActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

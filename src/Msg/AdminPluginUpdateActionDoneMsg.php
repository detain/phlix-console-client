<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * An update action (apply updates / set channel / set auto-update) succeeded.
 * Carries a ready-to-toast success message; the AdminPluginUpdateScreen toasts
 * it and refetches the update settings.
 */
final readonly class AdminPluginUpdateActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

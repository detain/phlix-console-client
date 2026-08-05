<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Plugin update settings failed to load. */
final readonly class AdminPluginUpdateFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

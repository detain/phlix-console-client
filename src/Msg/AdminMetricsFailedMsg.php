<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The admin metrics fetch failed (non-auth) — the screen shows the reason + a retry. */
final readonly class AdminMetricsFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

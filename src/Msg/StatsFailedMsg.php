<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The library fetch for the Stats panel failed (non-auth) — the screen shows the reason. */
final readonly class StatsFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

<?php

declare(strict_types=1);

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

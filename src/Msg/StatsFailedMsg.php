<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A stats overview fetch failed (non-auth) — the screen shows the reason in
 * its frame body instead of a toast that vanishes over a stuck spinner
 * [CU-021: review console__enduser #3, console__uxdesign #9].
 */
final readonly class StatsFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

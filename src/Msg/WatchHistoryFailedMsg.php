<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Watch history failed to load.
 */
final readonly class WatchHistoryFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

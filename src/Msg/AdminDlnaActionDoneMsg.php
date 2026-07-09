<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A DLNA start/stop action succeeded — carries the confirmation message to
 * toast; the screen refetches the status afterwards.
 */
final readonly class AdminDlnaActionDoneMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

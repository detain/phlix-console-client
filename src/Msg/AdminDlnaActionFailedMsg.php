<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * A DLNA start/stop action failed — carries the friendly server `message` (per
 * the message-not-error landmine) to toast; the status is left unchanged.
 */
final readonly class AdminDlnaActionFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

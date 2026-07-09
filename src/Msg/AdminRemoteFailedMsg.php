<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The remote-access status fetch failed — carries the friendly error to show on
 * the screen's error line.
 */
final readonly class AdminRemoteFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

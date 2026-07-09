<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Fetching the DLNA server status failed — carries the friendly error to render.
 */
final readonly class AdminDlnaFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

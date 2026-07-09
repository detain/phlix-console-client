<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** A library's scan history could not be loaded — the sub-view shows an error. */
final readonly class AdminLibraryScanHistoryFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

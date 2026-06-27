<?php

declare(strict_types=1);

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

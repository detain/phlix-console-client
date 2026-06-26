<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The library list could not be loaded — the screen shows an error + retry. */
final readonly class AdminLibrariesFailedMsg implements Msg
{
    public function __construct(
        public string $message,
    ) {
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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

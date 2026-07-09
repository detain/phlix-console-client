<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The library list failed to load (non-auth error); browse shows the reason. */
final readonly class LibrariesFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

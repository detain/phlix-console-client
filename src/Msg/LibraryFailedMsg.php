<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** A library grid fetch failed (non-auth); the LibraryScreen shows the reason. */
final readonly class LibraryFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

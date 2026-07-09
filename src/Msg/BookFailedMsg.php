<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Loading a single book's detail failed. */
final readonly class BookFailedMsg implements Msg
{
    public function __construct(
        public string $reason,
    ) {
    }
}

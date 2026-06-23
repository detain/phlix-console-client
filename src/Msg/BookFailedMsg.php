<?php

declare(strict_types=1);

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

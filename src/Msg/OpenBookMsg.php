<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Open a single book's detail — the App pushes a BookDetailScreen onto the stack. */
final readonly class OpenBookMsg implements Msg
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}

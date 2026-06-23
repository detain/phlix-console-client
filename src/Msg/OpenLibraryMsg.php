<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** Open a library's full grid — the App pushes a LibraryScreen onto the stack. */
final readonly class OpenLibraryMsg implements Msg
{
    public function __construct(
        public string $libraryId,
        public string $name,
        public string $type = '',
        public int $itemCount = 0,
    ) {
    }
}

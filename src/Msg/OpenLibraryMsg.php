<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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

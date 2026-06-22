<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Library;
use SugarCraft\Core\Msg;

/** The library list finished loading for the browse home. */
final readonly class LibrariesLoadedMsg implements Msg
{
    /** @param list<Library> $libraries */
    public function __construct(
        public array $libraries,
    ) {
    }
}

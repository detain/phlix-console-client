<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Library;
use SugarCraft\Core\Msg;

/** The media-library list arrived — the AdminLibrariesScreen builds its table. */
final readonly class AdminLibrariesLoadedMsg implements Msg
{
    /** @param list<Library> $libraries */
    public function __construct(
        public array $libraries,
    ) {
    }
}

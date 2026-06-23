<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Library;
use SugarCraft\Core\Msg;

/**
 * The libraries fetched when the command palette opened, to augment its action
 * registry with a "Go to <library>" entry for each (best-effort; the static
 * actions remain if the fetch fails).
 *
 * @phpstan-type LibraryList list<Library>
 */
final readonly class PaletteLibrariesLoadedMsg implements Msg
{
    /** @param list<Library> $libraries */
    public function __construct(
        public array $libraries,
    ) {
    }
}

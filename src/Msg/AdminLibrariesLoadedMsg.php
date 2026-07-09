<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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

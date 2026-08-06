<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Request the facets (genre list) for a library.
 * Fired when entering a library screen.
 */
final readonly class FetchFacetsMsg implements Msg
{
    public function __construct(
        public string $libraryId,
    ) {
    }
}

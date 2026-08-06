<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * The facets for a library resolved; the LibraryScreen populates its filter bar.
 *
 * @param array<string, list<string>> $facets e.g. ['genres' => ['Action', 'Comedy', ...]]
 */
final readonly class FacetsLoadedMsg implements Msg
{
    public function __construct(
        public array $facets,
        public int $generation,
    ) {
    }
}

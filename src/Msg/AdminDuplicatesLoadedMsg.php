<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/** The duplicate groups for a library arrived — the AdminDuplicatesScreen builds its list. */
final readonly class AdminDuplicatesLoadedMsg implements Msg
{
    /**
     * @param string $libraryId The library the groups belong to
     * @param list<array{canonical_key:string,type:string,library_id:string,primary:array<string,mixed>,duplicates:list<array<string,mixed>>}> $groups
     */
    public function __construct(
        public string $libraryId,
        public array $groups,
    ) {
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Emitted when the filesystem entries are loaded.
 *
 * @param list<array{name:string,path:string,type:string,size:int,modified:string}> $entries
 */
final readonly class AdminFilesystemLoadedMsg implements Msg
{
    /**
     * @param list<array{name:string,path:string,type:string,size:int,modified:string}> $entries
     */
    public function __construct(
        public array $entries,
        public string $currentPath,
    ) {
    }
}

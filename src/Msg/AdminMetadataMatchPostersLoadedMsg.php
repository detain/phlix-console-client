<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Poster candidates loaded for an item.
 */
final readonly class AdminMetadataMatchPostersLoadedMsg implements Msg
{
    /**
     * @param list<array{url:string,thumb:string,width:int,height:int}> $posters
     */
    public function __construct(
        public array $posters,
    ) {
    }
}

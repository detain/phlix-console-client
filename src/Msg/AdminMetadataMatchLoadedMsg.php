<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;

/**
 * Metadata match items loaded from the server.
 */
final readonly class AdminMetadataMatchLoadedMsg implements Msg
{
    /**
     * @param list<array{id:string,title:string,type:string,poster_url:?string}> $items
     */
    public function __construct(
        public array $items,
    ) {
    }
}

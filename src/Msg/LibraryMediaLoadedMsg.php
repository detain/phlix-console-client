<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\MediaPage;
use SugarCraft\Core\Msg;

/** A library's first page of media finished loading; populates its rail. */
final readonly class LibraryMediaLoadedMsg implements Msg
{
    public function __construct(
        public string $libraryId,
        public MediaPage $page,
    ) {
    }
}

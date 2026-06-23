<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Book;
use SugarCraft\Core\Msg;

/** A single book's detail (with its signed cover/download/read URLs) resolved. */
final readonly class BookLoadedMsg implements Msg
{
    public function __construct(
        public Book $book,
    ) {
    }
}

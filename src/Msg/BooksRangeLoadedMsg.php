<?php

declare(strict_types=1);

namespace Phlix\Console\Msg;

use Phlix\Console\Api\Dto\Book;
use SugarCraft\Core\Msg;

/**
 * A window of books for the {@see \Phlix\Console\Screen\BooksScreen} grid
 * resolved, keyed by ABSOLUTE index (the books endpoint sends no total, so the
 * screen already knows it from the library's item count). Carries the
 * `$generation` it was requested under so a result whose query was superseded
 * (e.g. a resize re-fetch) can be dropped.
 */
final readonly class BooksRangeLoadedMsg implements Msg
{
    /**
     * @param array<int, Book> $books absolute index → book
     */
    public function __construct(
        public array $books,
        public int $generation,
    ) {
    }
}

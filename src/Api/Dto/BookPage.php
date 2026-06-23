<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A page of books, mirroring the server's `/books` shape
 * `{books:[…], limit, offset}`.
 *
 * The server returns NO total count (the list is capped at the requested
 * `limit`, or up to 1000 across libraries when no `library_id` is given), so
 * unlike {@see MediaPage} this DTO has no `total`. Its `books` are raw rows
 * mapped through {@see Book::fromArray()}. Immutable.
 */
final readonly class BookPage
{
    /**
     * @param list<Book> $books
     */
    public function __construct(
        public array $books,
        public int $limit,
        public int $offset,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $books = [];
        foreach (Coerce::map($data['books'] ?? null) as $row) {
            if (is_array($row)) {
                $books[] = Book::fromArray($row);
            }
        }

        return new self(
            books: $books,
            limit: Coerce::int($data['limit'] ?? 0),
            offset: Coerce::int($data['offset'] ?? 0),
        );
    }
}

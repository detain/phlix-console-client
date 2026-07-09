<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A page of audiobooks, mirroring the server's `/audiobooks` shape
 * `{audiobooks:[…], limit, offset}`.
 *
 * The server sends NO total count (the list is capped server-side at 100), so
 * like {@see BookPage} this DTO has no `total`. Its `audiobooks` are raw list
 * rows mapped through {@see Audiobook::fromArray()}. Immutable.
 */
final readonly class AudiobookPage
{
    /**
     * @param list<Audiobook> $audiobooks
     */
    public function __construct(
        public array $audiobooks,
        public int $limit,
        public int $offset,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $audiobooks = [];
        foreach (Coerce::map($data['audiobooks'] ?? null) as $row) {
            if (is_array($row)) {
                $audiobooks[] = Audiobook::fromArray($row);
            }
        }

        return new self(
            audiobooks: $audiobooks,
            limit: Coerce::int($data['limit'] ?? 0),
            offset: Coerce::int($data['offset'] ?? 0),
        );
    }
}

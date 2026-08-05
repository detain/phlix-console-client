<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A page of music albums, mirroring the server's `/music/albums` shape
 * `{albums:[…], total, limit, offset}`. Albums are raw rows mapped through
 * {@see Album::fromArray()}. Immutable.
 */
final readonly class AlbumPage
{
    /**
     * @param list<Album> $albums
     */
    public function __construct(
        public array $albums,
        public int $total,
        public int $limit,
        public int $offset,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $albums = [];
        foreach (Coerce::map($data['albums'] ?? null) as $row) {
            if (is_array($row)) {
                $albums[] = Album::fromArray($row);
            }
        }

        $count = count($albums);

        return new self(
            albums: $albums,
            total: Coerce::int($data['total'] ?? $count, $count),
            limit: Coerce::int($data['limit'] ?? $count, $count),
            offset: Coerce::int($data['offset'] ?? 0),
        );
    }

    /** Whether more albums exist beyond this page. */
    public function hasMore(): bool
    {
        return $this->offset + count($this->albums) < $this->total;
    }
}

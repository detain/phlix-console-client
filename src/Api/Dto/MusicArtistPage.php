<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A page of music artists, mirroring the server's `/music/artists` shape
 * `{artists:[…], total, limit, offset}`. Artists are raw rows mapped through
 * {@see MusicArtist::fromArray()}. Immutable.
 */
final readonly class MusicArtistPage
{
    /**
     * @param list<MusicArtist> $artists
     */
    public function __construct(
        public array $artists,
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
        $artists = [];
        foreach (Coerce::map($data['artists'] ?? null) as $row) {
            if (is_array($row)) {
                $artists[] = MusicArtist::fromArray($row);
            }
        }

        $count = count($artists);

        return new self(
            artists: $artists,
            total: Coerce::int($data['total'] ?? $count, $count),
            limit: Coerce::int($data['limit'] ?? $count, $count),
            offset: Coerce::int($data['offset'] ?? 0),
        );
    }

    /** Whether more artists exist beyond this page. */
    public function hasMore(): bool
    {
        return $this->offset + count($this->artists) < $this->total;
    }
}

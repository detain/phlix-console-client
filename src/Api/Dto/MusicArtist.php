<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A music artist, mirroring the server's `/music/artists` shape
 * `{name, image_url, album_count, track_count, albums_truncated, albums:[…]}`.
 *
 * Artists are name-keyed (no client-visible integer PK). Immutable.
 */
final readonly class MusicArtist
{
    /**
     * @param list<string> $albums Embedded album titles (capped by the server)
     */
    public function __construct(
        public string $name,
        public ?string $imageUrl,
        public int $albumCount,
        public int $trackCount,
        public bool $albumsTruncated,
        public array $albums,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $albums = [];
        foreach (Coerce::map($data['albums'] ?? null) as $row) {
            if (is_string($row)) {
                $albums[] = $row;
            }
        }

        return new self(
            name: Coerce::str($data['name'] ?? ''),
            imageUrl: Coerce::nstr($data['image_url'] ?? null),
            albumCount: Coerce::int($data['album_count'] ?? 0),
            trackCount: Coerce::int($data['track_count'] ?? 0),
            albumsTruncated: (bool) ($data['albums_truncated'] ?? false),
            albums: $albums,
        );
    }
}

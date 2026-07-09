<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A music album, mirroring the server's `/music/albums` shape
 * `{name, artist, year, track_count, tracks:[…]}`.
 *
 * Albums have no id and no cover-art field server-side (there is no music cover
 * endpoint), so this DTO is text-forward. Its `tracks` are raw album-track rows
 * mapped through {@see Track::fromArray()}. Immutable.
 */
final readonly class Album
{
    /**
     * @param list<Track> $tracks
     */
    public function __construct(
        public string $name,
        public ?string $artist,
        public ?int $year,
        public int $trackCount,
        public array $tracks,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tracks = [];
        foreach (Coerce::map($data['tracks'] ?? null) as $row) {
            if (is_array($row)) {
                $tracks[] = Track::fromArray($row);
            }
        }

        return new self(
            name: Coerce::str($data['name'] ?? ''),
            artist: Coerce::nstr($data['artist'] ?? null),
            year: Coerce::nint($data['year'] ?? null),
            trackCount: array_key_exists('track_count', $data)
                ? Coerce::int($data['track_count'])
                : count($tracks),
            tracks: $tracks,
        );
    }
}

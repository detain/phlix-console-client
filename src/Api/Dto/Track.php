<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A music track, mirroring the server's two divergent track shapes.
 *
 * An album's `tracks[]` (from `/music/albums`) are RAW media-item rows whose
 * audio metadata is NESTED under a `metadata` key, while `/music/tracks`
 * returns a FLATTENED row with those fields hoisted to the top level (and
 * `name` already resolved to the title). {@see fromArray()} is tolerant of
 * both: it prefers the nested `metadata.<key>` and falls back to the flat
 * top-level key.
 *
 * `durationSecs` is seconds (the server's `duration_secs`), not ms or ticks.
 * Immutable.
 */
final readonly class Track
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $artist,
        public ?string $album,
        public ?int $trackNumber,
        public ?int $discNumber,
        public ?int $durationSecs,
        public ?int $year,
        public ?string $genre,
    ) {
    }

    /**
     * Build from either a raw album-track row (audio fields under `metadata`) or
     * a flat `/music/tracks` row (audio fields at the top level).
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $metadata = Coerce::map($data['metadata'] ?? null);

        return new self(
            id: Coerce::str($data['id'] ?? ''),
            title: Coerce::nstr($metadata['title'] ?? null)
                ?? Coerce::nstr($data['name'] ?? null)
                ?? Coerce::nstr($data['title'] ?? null)
                ?? '',
            artist: Coerce::nstr(self::pick($data, $metadata, 'artist')),
            album: Coerce::nstr(self::pick($data, $metadata, 'album')),
            trackNumber: Coerce::nint(self::pick($data, $metadata, 'track_number')),
            discNumber: Coerce::nint(self::pick($data, $metadata, 'disc_number')),
            durationSecs: Coerce::nint(self::pick($data, $metadata, 'duration_secs')),
            year: Coerce::nint(self::pick($data, $metadata, 'year')),
            genre: Coerce::nstr(self::pick($data, $metadata, 'genre')),
        );
    }

    /**
     * A human duration — `m:ss`, or `h:mm:ss` once an hour or longer. Empty
     * string when the duration is unknown.
     */
    public function durationLabel(): string
    {
        if ($this->durationSecs === null) {
            return '';
        }

        $total = max(0, $this->durationSecs);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $seconds = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Prefer the nested metadata value, falling back to the flat top-level key.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $metadata
     */
    private static function pick(array $data, array $metadata, string $key): mixed
    {
        return $metadata[$key] ?? $data[$key] ?? null;
    }
}

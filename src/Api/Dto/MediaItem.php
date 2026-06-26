<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A media item, mirroring the server's shaped media-item schema
 * (MediaItemShaper::shape / shapeDetail). Immutable.
 *
 * `streamUrl` is only present on the single-item detail endpoint (a signed
 * URL); it is null in list responses.
 */
final readonly class MediaItem
{
    /**
     * @param list<string> $genres
     * @param list<string> $actors
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $sortTitle,
        public string $type,
        public ?string $path,
        public ?string $posterUrl,
        public ?string $posterSrcset,
        public array $genres,
        public ?int $year,
        public ?string $rating,
        public ?int $runtime,
        public ?int $duration,
        public ?string $overview,
        public array $actors,
        public ?string $director,
        public ?string $parentId,
        public ?int $seasonNumber,
        public ?int $episodeNumber,
        public ?string $episodeTitle,
        public ?string $streamUrl,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /**
     * Build from a shaped media item (the `items[]` / `item` server shape).
     *
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            sortTitle: Coerce::str($data['sort_title'] ?? ($data['name'] ?? '')),
            type: Coerce::str($data['type'] ?? 'movie', 'movie'),
            path: Coerce::nstr($data['path'] ?? null),
            posterUrl: Coerce::nstr($data['poster_url'] ?? null),
            posterSrcset: Coerce::nstr($data['poster_srcset'] ?? null),
            genres: Coerce::stringList($data['genres'] ?? []),
            year: Coerce::nint($data['year'] ?? null),
            rating: Coerce::nstr($data['rating'] ?? null),
            runtime: Coerce::nint($data['runtime'] ?? null),
            duration: Coerce::nint($data['duration'] ?? null),
            overview: Coerce::nstr($data['overview'] ?? null),
            actors: Coerce::actorNames($data['actors'] ?? []),
            director: Coerce::nstr($data['director'] ?? null),
            parentId: Coerce::nstr($data['parent_id'] ?? null),
            seasonNumber: Coerce::nint($data['season_number'] ?? null),
            episodeNumber: Coerce::nint($data['episode_number'] ?? null),
            episodeTitle: Coerce::nstr($data['episode_title'] ?? null),
            streamUrl: Coerce::nstr($data['stream_url'] ?? null),
            createdAt: Coerce::nstr($data['created_at'] ?? null),
            updatedAt: Coerce::nstr($data['updated_at'] ?? null),
        );
    }

    /**
     * Build from a continue-watching row, whose media fields live in a nested
     * decoded `metadata` map and which uses `media_item_id` / `season` /
     * `episode` / `duration_seconds` instead of the shaped names.
     *
     * @param array<string,mixed> $row
     */
    public static function fromContinueWatching(array $row): self
    {
        $meta = Coerce::map($row['metadata'] ?? null);

        return new self(
            id: Coerce::str($row['media_item_id'] ?? ($row['id'] ?? '')),
            name: Coerce::str($row['name'] ?? ''),
            sortTitle: Coerce::str($row['name'] ?? ''),
            type: Coerce::str($row['type'] ?? 'movie', 'movie'),
            path: null,
            posterUrl: Coerce::nstr($meta['poster_url'] ?? null),
            posterSrcset: null,
            genres: Coerce::stringList($meta['genres'] ?? []),
            year: Coerce::nint($meta['year'] ?? null),
            rating: Coerce::nstr($meta['rating'] ?? null),
            runtime: Coerce::nint($meta['runtime'] ?? null),
            duration: Coerce::nint($meta['duration_seconds'] ?? null),
            overview: Coerce::nstr($meta['overview'] ?? null),
            actors: Coerce::actorNames($meta['actors'] ?? []),
            director: Coerce::nstr($meta['director'] ?? null),
            parentId: null,
            seasonNumber: Coerce::nint($meta['season'] ?? null),
            episodeNumber: Coerce::nint($meta['episode'] ?? null),
            episodeTitle: Coerce::nstr($meta['episode_title'] ?? null),
            streamUrl: null,
            createdAt: Coerce::nstr($row['created_at'] ?? null),
            updatedAt: Coerce::nstr($row['updated_at'] ?? null),
        );
    }

    /** Whether this item has a child hierarchy worth drilling into. */
    public function isContainer(): bool
    {
        return $this->type === 'series' || $this->type === 'season';
    }
}

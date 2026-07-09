<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A single rating record for a media item.
 *
 * Mirrors the server's rating shape from GET /api/v1/media/{id}/ratings.
 * Sources: 'tmdb', 'imdb', 'user'. Types: 'average', 'user', 'critic', 'meta'.
 * Score is a 0.0-10.0 float.
 *
 * @readonly
 */
final readonly class Rating
{
    /**
     * @param string $source   One of: 'tmdb', 'imdb', 'user'
     * @param string $type     One of: 'average', 'user', 'critic', 'meta'
     */
    public function __construct(
        public int $id,
        public string $mediaItemId,
        public string $source,
        public string $type,
        public float $score,
        public ?int $votes,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::int($data['id'] ?? 0),
            mediaItemId: Coerce::str($data['media_item_id'] ?? ''),
            source: Coerce::str($data['source'] ?? 'user'),
            type: Coerce::str($data['type'] ?? 'user'),
            score: is_numeric($data['score'] ?? null) ? (float) $data['score'] : 0.0,
            votes: Coerce::nint($data['votes'] ?? null),
        );
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * All ratings for a single media item, including the weighted aggregate.
 *
 * Mirrors the server's response from GET /api/v1/media/{id}/ratings.
 *
 * @readonly
 */
final readonly class MediaRatings
{
    /**
     * @param list<Rating> $ratings
     */
    public function __construct(
        public string $itemId,
        public array $ratings,
        public ?float $aggregateScore,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $ratings = [];
        foreach (Coerce::map($data['ratings'] ?? null) as $row) {
            if (is_array($row)) {
                $ratings[] = Rating::fromArray($row);
            }
        }

        return new self(
            itemId: Coerce::str($data['item_id'] ?? $data['media_item_id'] ?? ''),
            ratings: $ratings,
            aggregateScore: isset($data['aggregate_score']) && is_numeric($data['aggregate_score'])
                ? (float) $data['aggregate_score']
                : null,
        );
    }

    /**
     * Get the user's personal rating for this item, if set.
     */
    public function userRating(): ?Rating
    {
        foreach ($this->ratings as $rating) {
            if ($rating->source === 'user' && $rating->type === 'user') {
                return $rating;
            }
        }

        return null;
    }

    /**
     * Get the aggregate (average) rating for display.
     */
    public function displayScore(): ?float
    {
        return $this->aggregateScore;
    }
}

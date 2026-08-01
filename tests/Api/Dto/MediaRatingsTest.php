<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\MediaRatings;
use Phlix\Console\Api\Dto\Rating;
use PHPUnit\Framework\TestCase;

final class MediaRatingsTest extends TestCase
{
    public function testFromArrayWithAllFields(): void
    {
        $ratings = MediaRatings::fromArray([
            'item_id' => 'movie-123',
            'ratings' => [
                ['id' => 1, 'media_item_id' => 'movie-123', 'source' => 'tmdb', 'type' => 'average', 'score' => 8.5, 'votes' => 1000],
                ['id' => 2, 'media_item_id' => 'movie-123', 'source' => 'imdb', 'type' => 'average', 'score' => 8.0, 'votes' => 500],
            ],
            'aggregate_score' => 8.25,
        ]);

        self::assertSame('movie-123', $ratings->itemId);
        self::assertCount(2, $ratings->ratings);
        self::assertSame(8.25, $ratings->aggregateScore);
    }

    public function testFromArrayWithMediaItemIdFallback(): void
    {
        $ratings = MediaRatings::fromArray([
            'media_item_id' => 'movie-456',
            'ratings' => [],
            'aggregate_score' => 7.0,
        ]);

        self::assertSame('movie-456', $ratings->itemId);
    }

    public function testFromArrayWithNullAggregateScore(): void
    {
        $ratings = MediaRatings::fromArray([
            'item_id' => 'movie-123',
            'ratings' => [],
        ]);

        self::assertNull($ratings->aggregateScore);
    }

    public function testFromArrayWithNonNumericAggregateScore(): void
    {
        $ratings = MediaRatings::fromArray([
            'item_id' => 'movie-123',
            'ratings' => [],
            'aggregate_score' => 'not-a-number',
        ]);

        self::assertNull($ratings->aggregateScore);
    }

    public function testUserRatingReturnsUserRating(): void
    {
        $ratings = MediaRatings::fromArray([
            'item_id' => 'movie-123',
            'ratings' => [
                ['id' => 1, 'media_item_id' => 'movie-123', 'source' => 'tmdb', 'type' => 'average', 'score' => 8.5, 'votes' => 1000],
                ['id' => 2, 'media_item_id' => 'movie-123', 'source' => 'user', 'type' => 'user', 'score' => 9.0, 'votes' => 1],
            ],
            'aggregate_score' => 8.5,
        ]);

        $userRating = $ratings->userRating();
        self::assertInstanceOf(Rating::class, $userRating);
        self::assertSame('user', $userRating->source);
        self::assertSame('user', $userRating->type);
        self::assertSame(9.0, $userRating->score);
    }

    public function testUserRatingReturnsNullWhenNoUserRating(): void
    {
        $ratings = MediaRatings::fromArray([
            'item_id' => 'movie-123',
            'ratings' => [
                ['id' => 1, 'media_item_id' => 'movie-123', 'source' => 'tmdb', 'type' => 'average', 'score' => 8.5, 'votes' => 1000],
            ],
            'aggregate_score' => 8.5,
        ]);

        self::assertNull($ratings->userRating());
    }

    public function testDisplayScoreReturnsAggregateScore(): void
    {
        $ratings = MediaRatings::fromArray([
            'item_id' => 'movie-123',
            'ratings' => [],
            'aggregate_score' => 7.5,
        ]);

        self::assertSame(7.5, $ratings->displayScore());
    }

    public function testDisplayScoreReturnsNullWhenNoAggregate(): void
    {
        $ratings = MediaRatings::fromArray([
            'item_id' => 'movie-123',
            'ratings' => [],
        ]);

        self::assertNull($ratings->displayScore());
    }

    public function testFromArrayFiltersInvalidRatingRows(): void
    {
        $ratings = MediaRatings::fromArray([
            'item_id' => 'movie-123',
            'ratings' => [
                null,
                false,
                'not-an-array',
                ['id' => 1, 'media_item_id' => 'movie-123', 'source' => 'tmdb', 'type' => 'average', 'score' => 8.5, 'votes' => 1000],
            ],
        ]);

        self::assertCount(1, $ratings->ratings);
    }
}

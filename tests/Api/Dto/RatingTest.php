<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Rating;
use PHPUnit\Framework\TestCase;

final class RatingTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $rating = Rating::fromArray([
            'id' => 42,
            'media_item_id' => 'movie-123',
            'source' => 'tmdb',
            'type' => 'average',
            'score' => 7.5,
            'votes' => 1234,
        ]);

        self::assertSame(42, $rating->id);
        self::assertSame('movie-123', $rating->mediaItemId);
        self::assertSame('tmdb', $rating->source);
        self::assertSame('average', $rating->type);
        self::assertSame(7.5, $rating->score);
        self::assertSame(1234, $rating->votes);
    }

    public function testFromArrayWithNumericStringScore(): void
    {
        $rating = Rating::fromArray([
            'id' => 1,
            'media_item_id' => 'm1',
            'source' => 'imdb',
            'type' => 'user',
            'score' => '8.5',
        ]);

        self::assertSame(8.5, $rating->score);
        self::assertNull($rating->votes);
    }

    public function testFromArrayDefaultsForMissingFields(): void
    {
        $rating = Rating::fromArray([]);

        self::assertSame(0, $rating->id);
        self::assertSame('', $rating->mediaItemId);
        self::assertSame('user', $rating->source);
        self::assertSame('user', $rating->type);
        self::assertSame(0.0, $rating->score);
        self::assertNull($rating->votes);
    }

    public function testFromArrayWithNullScore(): void
    {
        $rating = Rating::fromArray([
            'id' => 1,
            'media_item_id' => 'm1',
            'score' => null,
        ]);

        self::assertSame(0.0, $rating->score);
    }

    public function testFromArrayWithNonNumericScore(): void
    {
        $rating = Rating::fromArray([
            'id' => 1,
            'media_item_id' => 'm1',
            'score' => 'not-a-number',
        ]);

        self::assertSame(0.0, $rating->score);
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\MediaItem;
use PHPUnit\Framework\TestCase;

final class MediaItemTest extends TestCase
{
    public function testFromArrayMapsEveryShapedField(): void
    {
        $item = MediaItem::fromArray([
            'id' => 'abc-123',
            'name' => 'The Matrix',
            'sort_title' => 'Matrix',
            'type' => 'movie',
            'path' => '/media/matrix.mkv',
            'poster_url' => 'https://srv/p.jpg',
            'poster_srcset' => 'https://srv/p.jpg 1x',
            'genres' => ['Action', 'Sci-Fi'],
            'year' => 1999,
            'rating' => 'R',
            'runtime' => 136,
            'duration' => 8160,
            'overview' => 'A hacker learns the truth.',
            'actors' => ['Keanu Reeves'],
            'director' => 'The Wachowskis',
            'parent_id' => null,
            'season_number' => null,
            'episode_number' => null,
            'episode_title' => null,
            'stream_url' => 'https://srv/stream?sig=x',
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-02-01 00:00:00',
        ]);

        self::assertSame('abc-123', $item->id);
        self::assertSame('The Matrix', $item->name);
        self::assertSame('Matrix', $item->sortTitle);
        self::assertSame('movie', $item->type);
        self::assertSame('/media/matrix.mkv', $item->path);
        self::assertSame('https://srv/p.jpg', $item->posterUrl);
        self::assertSame('https://srv/p.jpg 1x', $item->posterSrcset);
        self::assertSame(['Action', 'Sci-Fi'], $item->genres);
        self::assertSame(1999, $item->year);
        self::assertSame('R', $item->rating);
        self::assertSame(136, $item->runtime);
        self::assertSame(8160, $item->duration);
        self::assertSame('A hacker learns the truth.', $item->overview);
        self::assertSame(['Keanu Reeves'], $item->actors);
        self::assertSame('The Wachowskis', $item->director);
        self::assertNull($item->parentId);
        self::assertSame('https://srv/stream?sig=x', $item->streamUrl);
        self::assertSame('2020-02-01 00:00:00', $item->updatedAt);
    }

    public function testFromArrayDefaultsAndNumericStrings(): void
    {
        $item = MediaItem::fromArray([
            'id' => 1,                 // numeric id from DB
            'name' => 'Episode',
            'type' => 'episode',
            'year' => '2021',          // numeric string
            'season_number' => '2',
            'episode_number' => '5',
        ]);

        self::assertSame('1', $item->id);
        self::assertSame('Episode', $item->sortTitle, 'sort_title falls back to name');
        self::assertSame(2021, $item->year);
        self::assertSame(2, $item->seasonNumber);
        self::assertSame(5, $item->episodeNumber);
        self::assertNull($item->posterUrl);
        self::assertSame([], $item->genres);
        self::assertSame([], $item->actors);
        self::assertNull($item->streamUrl);
    }

    public function testFromArrayDefaultsTypeToMovie(): void
    {
        $item = MediaItem::fromArray(['id' => 'x', 'name' => 'No Type']);

        self::assertSame('movie', $item->type);
    }

    public function testFromArrayNormalisesActorObjects(): void
    {
        $item = MediaItem::fromArray([
            'id' => 'x',
            'name' => 'Cast',
            'actors' => [
                ['name' => 'Actor One', 'character' => 'Hero'],
                'Actor Two',
            ],
        ]);

        self::assertSame(['Actor One', 'Actor Two'], $item->actors);
    }

    public function testFromContinueWatchingPullsFromNestedMetadata(): void
    {
        $item = MediaItem::fromContinueWatching([
            'id' => 'playback-state-id',
            'media_item_id' => 'media-42',
            'name' => 'Dr. Stone',
            'type' => 'episode',
            'metadata' => [
                'poster_url' => 'https://srv/drstone.jpg',
                'year' => 2019,
                'rating' => 'TV-14',
                'runtime' => 24,
                'duration_seconds' => 1440,
                'genres' => ['Anime'],
                'overview' => 'Science!',
                'actors' => [['name' => 'Yusuke Kobayashi']],
                'director' => 'Iino',
                'season' => 1,
                'episode' => 3,
                'episode_title' => 'Weapons of Science',
            ],
        ]);

        self::assertSame('media-42', $item->id, 'uses media_item_id, not the playback-state id');
        self::assertSame('Dr. Stone', $item->name);
        self::assertSame('episode', $item->type);
        self::assertSame('https://srv/drstone.jpg', $item->posterUrl);
        self::assertSame(2019, $item->year);
        self::assertSame(1440, $item->duration);
        self::assertSame(['Anime'], $item->genres);
        self::assertSame(['Yusuke Kobayashi'], $item->actors);
        self::assertSame(1, $item->seasonNumber);
        self::assertSame(3, $item->episodeNumber);
        self::assertSame('Weapons of Science', $item->episodeTitle);
    }

    public function testFromContinueWatchingPrefersTopLevelPosterUrl(): void
    {
        // The server re-mints the top-level poster_url (fresh artwork signature);
        // fromContinueWatching must PREFER it over the nested metadata value so the
        // console fetches a non-expired artwork URL.
        $item = MediaItem::fromContinueWatching([
            'media_item_id' => 'media-42',
            'name' => 'Dr. Stone',
            'type' => 'episode',
            'poster_url' => '/api/v1/artwork/series-1?size=w500&exp=999&sig=fresh',
            'metadata' => [
                'poster_url' => '/api/v1/artwork/series-1?size=w500&exp=1&sig=stale',
            ],
        ]);

        self::assertSame('/api/v1/artwork/series-1?size=w500&exp=999&sig=fresh', $item->posterUrl);
    }

    public function testFromContinueWatchingFallsBackToNestedPosterUrl(): void
    {
        // When the top-level poster_url is absent, fall back to the nested value.
        $item = MediaItem::fromContinueWatching([
            'media_item_id' => 'media-42',
            'name' => 'Dr. Stone',
            'type' => 'episode',
            'metadata' => [
                'poster_url' => 'https://srv/drstone.jpg',
            ],
        ]);

        self::assertSame('https://srv/drstone.jpg', $item->posterUrl);
    }

    public function testFromContinueWatchingToleratesMissingMetadata(): void
    {
        $item = MediaItem::fromContinueWatching([
            'media_item_id' => 'm1',
            'name' => 'Bare',
            'type' => 'movie',
        ]);

        self::assertSame('m1', $item->id);
        self::assertNull($item->posterUrl);
        self::assertNull($item->year);
        self::assertSame([], $item->genres);
    }

    public function testIsContainer(): void
    {
        self::assertTrue(MediaItem::fromArray(['id' => 's', 'name' => 'S', 'type' => 'series'])->isContainer());
        self::assertTrue(MediaItem::fromArray(['id' => 's', 'name' => 'S', 'type' => 'season'])->isContainer());
        self::assertFalse(MediaItem::fromArray(['id' => 'm', 'name' => 'M', 'type' => 'movie'])->isContainer());
        self::assertFalse(MediaItem::fromArray(['id' => 'e', 'name' => 'E', 'type' => 'episode'])->isContainer());
    }
}

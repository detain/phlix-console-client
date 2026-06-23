<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\Track;
use PHPUnit\Framework\TestCase;

final class AlbumTest extends TestCase
{
    public function testFromArrayMapsAFullAlbumWithTracks(): void
    {
        $album = Album::fromArray([
            'name' => 'Abbey Road',
            'artist' => 'The Beatles',
            'year' => 1969,
            'track_count' => 2,
            'tracks' => [
                ['id' => 't1', 'name' => 'x', 'metadata' => ['title' => 'Come Together', 'track_number' => 1, 'duration_secs' => 259]],
                ['id' => 't2', 'name' => 'x', 'metadata' => ['title' => 'Something', 'track_number' => 2, 'duration_secs' => 182]],
            ],
        ]);

        self::assertSame('Abbey Road', $album->name);
        self::assertSame('The Beatles', $album->artist);
        self::assertSame(1969, $album->year);
        self::assertSame(2, $album->trackCount);
        self::assertContainsOnlyInstancesOf(Track::class, $album->tracks);
        self::assertCount(2, $album->tracks);
        self::assertSame('Come Together', $album->tracks[0]->title);
        self::assertSame('Something', $album->tracks[1]->title);
    }

    public function testTrackCountFallsBackToTrackCountWhenAbsent(): void
    {
        $album = Album::fromArray([
            'name' => 'EP',
            'tracks' => [
                ['id' => 't1', 'name' => 'A'],
                ['id' => 't2', 'name' => 'B'],
                ['id' => 't3', 'name' => 'C'],
            ],
        ]);

        self::assertSame(3, $album->trackCount, 'absent track_count falls back to count(tracks)');
    }

    public function testExplicitTrackCountIsUsedEvenWhenItDiffersFromTrackList(): void
    {
        $album = Album::fromArray([
            'name' => 'Partial',
            'track_count' => 12,
            'tracks' => [['id' => 't1', 'name' => 'A']],
        ]);

        self::assertSame(12, $album->trackCount);
        self::assertCount(1, $album->tracks);
    }

    public function testMissingTracksBecomeEmptyList(): void
    {
        $album = Album::fromArray(['name' => 'Empty']);

        self::assertSame([], $album->tracks);
        self::assertSame(0, $album->trackCount, 'no track_count, no tracks → 0');
    }

    public function testEmptyTracksList(): void
    {
        $album = Album::fromArray(['name' => 'Empty', 'tracks' => []]);

        self::assertSame([], $album->tracks);
        self::assertSame(0, $album->trackCount);
    }

    public function testNonArrayTrackRowsAreSkipped(): void
    {
        $album = Album::fromArray([
            'name' => 'Mixed',
            'tracks' => [
                ['id' => 't1', 'name' => 'Good'],
                'garbage',
                42,
                null,
                ['id' => 't2', 'name' => 'Also Good'],
            ],
        ]);

        self::assertCount(2, $album->tracks, 'non-array rows are skipped');
        self::assertSame('Good', $album->tracks[0]->title);
        self::assertSame('Also Good', $album->tracks[1]->title);
        self::assertSame(2, $album->trackCount, 'fallback counts only mapped tracks');
    }

    public function testDefaultsAndNumericStrings(): void
    {
        $album = Album::fromArray([
            'name' => 'Strings',
            'year' => '1971',     // numeric string
            'track_count' => '9', // numeric string
        ]);

        self::assertSame('Strings', $album->name);
        self::assertNull($album->artist);
        self::assertSame(1971, $album->year);
        self::assertSame(9, $album->trackCount);
    }

    public function testMissingNameBecomesEmptyString(): void
    {
        $album = Album::fromArray([]);

        self::assertSame('', $album->name);
        self::assertNull($album->artist);
        self::assertNull($album->year);
    }
}

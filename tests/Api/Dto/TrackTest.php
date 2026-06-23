<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Track;
use PHPUnit\Framework\TestCase;

final class TrackTest extends TestCase
{
    public function testMapsTheNestedAlbumRawShape(): void
    {
        $track = Track::fromArray([
            'id' => 'uuid-1',
            'name' => '01 - Come Together.flac', // raw filename, NOT the title
            'path' => '/music/abbey-road/01.flac',
            'type' => 'track',
            'metadata' => [
                'title' => 'Come Together',
                'artist' => 'The Beatles',
                'album' => 'Abbey Road',
                'album_artist' => 'The Beatles',
                'year' => 1969,
                'genre' => 'Rock',
                'track_number' => 1,
                'disc_number' => 1,
                'duration_secs' => 259,
                'composer' => 'Lennon-McCartney',
            ],
        ]);

        self::assertSame('uuid-1', $track->id);
        self::assertSame('Come Together', $track->title, 'metadata.title wins over the raw filename name');
        self::assertSame('The Beatles', $track->artist);
        self::assertSame('Abbey Road', $track->album);
        self::assertSame(1, $track->trackNumber);
        self::assertSame(1, $track->discNumber);
        self::assertSame(259, $track->durationSecs);
        self::assertSame(1969, $track->year);
        self::assertSame('Rock', $track->genre);
    }

    public function testMapsTheFlatFormatTrackShape(): void
    {
        $track = Track::fromArray([
            'id' => 'uuid-2',
            'name' => 'Something', // already the resolved title in the flat shape
            'artist' => 'The Beatles',
            'album' => 'Abbey Road',
            'album_artist' => 'The Beatles',
            'year' => '1969',          // numeric string
            'genre' => 'Rock',
            'track_number' => '2',
            'disc_number' => '1',
            'duration_secs' => '182',
            'composer' => 'George Harrison',
            'path' => '/music/abbey-road/02.flac',
        ]);

        self::assertSame('uuid-2', $track->id);
        self::assertSame('Something', $track->title, 'flat shape: name is the title');
        self::assertSame('The Beatles', $track->artist);
        self::assertSame('Abbey Road', $track->album);
        self::assertSame(2, $track->trackNumber);
        self::assertSame(1, $track->discNumber);
        self::assertSame(182, $track->durationSecs);
        self::assertSame(1969, $track->year);
        self::assertSame('Rock', $track->genre);
    }

    public function testTitlePrefersMetadataThenNameThenTitle(): void
    {
        // metadata.title wins.
        self::assertSame('Meta', Track::fromArray([
            'name' => 'Name',
            'title' => 'Top',
            'metadata' => ['title' => 'Meta'],
        ])->title);

        // no metadata.title → falls back to top-level name.
        self::assertSame('Name', Track::fromArray([
            'name' => 'Name',
            'title' => 'Top',
        ])->title);

        // no name → falls back to top-level title.
        self::assertSame('Top', Track::fromArray([
            'title' => 'Top',
        ])->title);
    }

    public function testNestedMetadataIsPreferredOverFlatTopLevel(): void
    {
        $track = Track::fromArray([
            'id' => 'x',
            'name' => 'Title',
            'artist' => 'Flat Artist',
            'track_number' => 9,
            'metadata' => [
                'artist' => 'Nested Artist',
                'track_number' => 3,
            ],
        ]);

        self::assertSame('Nested Artist', $track->artist, 'nested metadata wins');
        self::assertSame(3, $track->trackNumber, 'nested metadata wins');
    }

    public function testFlatTopLevelUsedWhenMetadataKeyAbsent(): void
    {
        $track = Track::fromArray([
            'id' => 'x',
            'name' => 'Title',
            'artist' => 'Flat Artist',
            'track_number' => 7,
            'metadata' => [], // present but empty
        ]);

        self::assertSame('Flat Artist', $track->artist);
        self::assertSame(7, $track->trackNumber);
    }

    public function testMissingAndNullFieldsBecomeNull(): void
    {
        $track = Track::fromArray(['id' => 'x', 'name' => 'Bare']);

        self::assertSame('x', $track->id);
        self::assertSame('Bare', $track->title);
        self::assertNull($track->artist);
        self::assertNull($track->album);
        self::assertNull($track->trackNumber);
        self::assertNull($track->discNumber);
        self::assertNull($track->durationSecs);
        self::assertNull($track->year);
        self::assertNull($track->genre);
    }

    public function testMissingTitleEverywhereBecomesEmptyString(): void
    {
        self::assertSame('', Track::fromArray(['id' => 'x'])->title);
    }

    public function testMissingIdBecomesEmptyString(): void
    {
        self::assertSame('', Track::fromArray(['name' => 'No Id'])->id);
    }

    public function testDurationLabelUnderAnHour(): void
    {
        $track = Track::fromArray(['id' => 'x', 'name' => 'T', 'duration_secs' => 245]);

        self::assertSame('4:05', $track->durationLabel());
    }

    public function testDurationLabelAtAndOverAnHour(): void
    {
        self::assertSame('1:01:01', Track::fromArray(['id' => 'x', 'name' => 'T', 'duration_secs' => 3661])->durationLabel());
        self::assertSame('1:00:00', Track::fromArray(['id' => 'x', 'name' => 'T', 'duration_secs' => 3600])->durationLabel());
    }

    public function testDurationLabelZeroPadsSeconds(): void
    {
        self::assertSame('0:05', Track::fromArray(['id' => 'x', 'name' => 'T', 'duration_secs' => 5])->durationLabel());
    }

    public function testDurationLabelNullDurationIsEmptyString(): void
    {
        self::assertSame('', Track::fromArray(['id' => 'x', 'name' => 'T'])->durationLabel());
    }
}

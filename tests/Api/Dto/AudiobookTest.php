<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Audiobook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AudiobookTest extends TestCase
{
    public function testMapsTheRawListShapeWithNestedMetadataAndNoStreamUrl(): void
    {
        $audiobook = Audiobook::fromArray([
            'id' => 'a1',
            'name' => 'dune.m4b', // raw filename
            'type' => 'audiobook',
            'library_id' => 'lib-1',
            'path' => '/audiobooks/scifi/dune.m4b',
            'metadata' => [
                'author' => 'Frank Herbert',
                'narrator' => 'Scott Brick',
                'series' => 'Dune',
                'series_position' => 1,
                'description' => 'A desert planet epic.',
                'duration_ms' => 75600000,
                'language' => 'en',
                'cover_path' => '/var/data/covers/dune.jpg', // filesystem path — unusable, not exposed
            ],
        ]);

        self::assertSame('a1', $audiobook->id);
        self::assertSame('dune.m4b', $audiobook->title, 'falls back to name when no title is present');
        self::assertSame('Frank Herbert', $audiobook->author);
        self::assertSame('Scott Brick', $audiobook->narrator);
        self::assertSame('Dune', $audiobook->series);
        self::assertSame(1, $audiobook->seriesPosition);
        self::assertSame('A desert planet epic.', $audiobook->description);
        self::assertSame(75600000, $audiobook->durationMs);
        self::assertSame('en', $audiobook->language);
        self::assertNull($audiobook->streamUrl, 'the list shape carries no stream URL');
    }

    public function testMapsTheFlatDetailShapeWithStreamUrl(): void
    {
        $audiobook = Audiobook::fromArray([
            'id' => 'a1',
            'title' => 'Dune',
            'author' => 'Frank Herbert',
            'narrator' => 'Scott Brick',
            'series' => 'Dune',
            'series_position' => 1,
            'description' => 'A desert planet epic.',
            'duration_ms' => 75600000,
            'language' => 'en',
            'cover_url' => '/var/data/covers/dune.jpg', // filesystem path — deliberately NOT exposed
            'stream_url' => '/api/v1/audiobooks/a1/stream?sig=abc',
            'read_url' => '/api/v1/audiobooks/a1/read?sig=def',
        ]);

        self::assertSame('a1', $audiobook->id);
        self::assertSame('Dune', $audiobook->title);
        self::assertSame('Frank Herbert', $audiobook->author);
        self::assertSame('Scott Brick', $audiobook->narrator);
        self::assertSame('Dune', $audiobook->series);
        self::assertSame(1, $audiobook->seriesPosition);
        self::assertSame('A desert planet epic.', $audiobook->description);
        self::assertSame(75600000, $audiobook->durationMs);
        self::assertSame('en', $audiobook->language);
        self::assertSame('/api/v1/audiobooks/a1/stream?sig=abc', $audiobook->streamUrl);
    }

    public function testTitlePrefersFlatThenMetadataThenName(): void
    {
        // flat title wins over metadata.title and name.
        self::assertSame('Flat', Audiobook::fromArray([
            'title' => 'Flat',
            'name' => 'Name',
            'metadata' => ['title' => 'Meta'],
        ])->title);

        // no flat title → metadata.title.
        self::assertSame('Meta', Audiobook::fromArray([
            'name' => 'Name',
            'metadata' => ['title' => 'Meta'],
        ])->title);

        // neither → falls back to name.
        self::assertSame('Name', Audiobook::fromArray([
            'name' => 'Name',
        ])->title);
    }

    public function testMissingTitleEverywhereBecomesEmptyString(): void
    {
        self::assertSame('', Audiobook::fromArray(['id' => 'a1'])->title);
    }

    public function testMissingIdBecomesEmptyString(): void
    {
        self::assertSame('', Audiobook::fromArray(['title' => 'No Id'])->id);
    }

    public function testNestedMetadataFallbackForEveryOptionalStringField(): void
    {
        // No flat keys at all → each pulls from metadata.
        $audiobook = Audiobook::fromArray([
            'id' => 'a1',
            'name' => 'x.m4b',
            'metadata' => [
                'author' => 'Author M',
                'narrator' => 'Narrator M',
                'series' => 'Series M',
                'description' => 'Desc M',
                'language' => 'de',
            ],
        ]);

        self::assertSame('Author M', $audiobook->author);
        self::assertSame('Narrator M', $audiobook->narrator);
        self::assertSame('Series M', $audiobook->series);
        self::assertSame('Desc M', $audiobook->description);
        self::assertSame('de', $audiobook->language);
    }

    public function testFlatKeysWinOverNestedMetadata(): void
    {
        $audiobook = Audiobook::fromArray([
            'id' => 'a1',
            'author' => 'Flat Author',
            'narrator' => 'Flat Narrator',
            'series' => 'Flat Series',
            'description' => 'Flat Desc',
            'language' => 'fr',
            'metadata' => [
                'author' => 'Meta Author',
                'narrator' => 'Meta Narrator',
                'series' => 'Meta Series',
                'description' => 'Meta Desc',
                'language' => 'es',
            ],
        ]);

        self::assertSame('Flat Author', $audiobook->author);
        self::assertSame('Flat Narrator', $audiobook->narrator);
        self::assertSame('Flat Series', $audiobook->series);
        self::assertSame('Flat Desc', $audiobook->description);
        self::assertSame('fr', $audiobook->language);
    }

    public function testMissingOptionalFieldsBecomeNull(): void
    {
        $audiobook = Audiobook::fromArray(['id' => 'a1', 'name' => 'Bare']);

        self::assertSame('a1', $audiobook->id);
        self::assertSame('Bare', $audiobook->title);
        self::assertNull($audiobook->author);
        self::assertNull($audiobook->narrator);
        self::assertNull($audiobook->series);
        self::assertNull($audiobook->seriesPosition);
        self::assertNull($audiobook->description);
        self::assertNull($audiobook->durationMs);
        self::assertNull($audiobook->language);
        self::assertNull($audiobook->streamUrl);
    }

    public function testSeriesPositionAndDurationCoerceFromNumericStrings(): void
    {
        $flat = Audiobook::fromArray([
            'id' => 'a1',
            'series_position' => '3',
            'duration_ms' => '3600000',
        ]);
        self::assertSame(3, $flat->seriesPosition);
        self::assertSame(3600000, $flat->durationMs);

        // From nested metadata, also as numeric strings.
        $nested = Audiobook::fromArray([
            'id' => 'a2',
            'metadata' => ['series_position' => '7', 'duration_ms' => '120000'],
        ]);
        self::assertSame(7, $nested->seriesPosition);
        self::assertSame(120000, $nested->durationMs);
    }

    public function testSeriesPositionAndDurationFallBackToMetadataWhenFlatAbsent(): void
    {
        $audiobook = Audiobook::fromArray([
            'id' => 'a1',
            'metadata' => ['series_position' => 2, 'duration_ms' => 5000],
        ]);

        self::assertSame(2, $audiobook->seriesPosition);
        self::assertSame(5000, $audiobook->durationMs);
    }

    #[DataProvider('durationLabelProvider')]
    public function testDurationLabel(?int $durationMs, string $expected): void
    {
        $audiobook = new Audiobook('a1', 'T', null, null, null, null, null, $durationMs, null, null);

        self::assertSame($expected, $audiobook->durationLabel());
    }

    /** @return iterable<string, array{0: ?int, 1: string}> */
    public static function durationLabelProvider(): iterable
    {
        yield 'null is empty string' => [null, ''];
        yield 'zero' => [0, '0:00'];
        yield 'sub-minute' => [59000, '0:59'];
        yield 'one minute' => [60000, '1:00'];
        yield 'just under an hour' => [3599000, '59:59'];
        yield 'one hour' => [3600000, '1:00:00'];
        yield 'past an hour' => [3661000, '1:01:01'];
        yield 'sub-second rounds down' => [1999, '0:01'];
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Photo;
use Phlix\Console\Api\Dto\PhotoExif;
use PHPUnit\Framework\TestCase;

final class PhotoTest extends TestCase
{
    public function testMapsTheListRowShapeWithoutExif(): void
    {
        // An album/list row: signed thumbnail/full URLs, but no `exif`.
        $photo = Photo::fromArray([
            'id' => 'p1',
            'name' => 'IMG_0042.jpg',
            'path' => '/photos/2023/IMG_0042.jpg',
            'type' => 'photo',
            'metadata' => ['camera_make' => 'Canon'],
            'thumbnail_url' => '/api/v1/photo/photos/p1/thumbnail?sig=abc',
            'full_url' => '/api/v1/photo/photos/p1/full?sig=def',
        ]);

        self::assertSame('p1', $photo->id);
        self::assertSame('IMG_0042.jpg', $photo->name);
        self::assertSame('/api/v1/photo/photos/p1/thumbnail?sig=abc', $photo->thumbnailUrl);
        self::assertSame('/api/v1/photo/photos/p1/full?sig=def', $photo->fullUrl);
        self::assertNull($photo->exif, 'list rows carry no exif (the grid only needs the thumbnail)');
    }

    public function testMapsTheDetailShapeWithExif(): void
    {
        $photo = Photo::fromArray([
            'id' => 'p1',
            'name' => 'IMG_0042.jpg',
            'path' => '/photos/2023/IMG_0042.jpg',
            'metadata' => ['camera_make' => 'Canon', 'camera_model' => 'EOS R5'],
            'exif' => ['camera_make' => 'Canon', 'camera_model' => 'EOS R5', 'iso' => 400],
            'thumbnail_url' => '/api/v1/photo/photos/p1/thumbnail?sig=abc',
            'full_url' => '/api/v1/photo/photos/p1/full?sig=def',
        ]);

        self::assertSame('p1', $photo->id);
        self::assertInstanceOf(PhotoExif::class, $photo->exif);
        self::assertSame('Canon', $photo->exif->cameraMake);
        self::assertSame('EOS R5', $photo->exif->cameraModel);
        self::assertSame(400, $photo->exif->iso);
        self::assertSame('/api/v1/photo/photos/p1/thumbnail?sig=abc', $photo->thumbnailUrl);
        self::assertSame('/api/v1/photo/photos/p1/full?sig=def', $photo->fullUrl);
    }

    public function testMissingIdBecomesEmptyString(): void
    {
        self::assertSame('', Photo::fromArray(['name' => 'No Id'])->id);
    }

    public function testMissingNameFallsBackToEmptyString(): void
    {
        $photo = Photo::fromArray(['id' => 'p1']);

        self::assertSame('p1', $photo->id);
        self::assertSame('', $photo->name);
    }

    public function testMissingUrlsAndExifBecomeNull(): void
    {
        $photo = Photo::fromArray(['id' => 'p1', 'name' => 'Bare']);

        self::assertNull($photo->thumbnailUrl);
        self::assertNull($photo->fullUrl);
        self::assertNull($photo->exif);
    }

    public function testNonArrayExifIsIgnored(): void
    {
        // A scalar `exif` must not crash PhotoExif::fromArray — it stays null.
        $photo = Photo::fromArray(['id' => 'p1', 'name' => 'x', 'exif' => 'garbage']);

        self::assertNull($photo->exif);
    }

    public function testEmptyUrlStringsBecomeNull(): void
    {
        $photo = Photo::fromArray([
            'id' => 'p1',
            'name' => 'x',
            'thumbnail_url' => '',
            'full_url' => '',
        ]);

        self::assertNull($photo->thumbnailUrl);
        self::assertNull($photo->fullUrl);
    }
}

<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Photo;
use Phlix\Console\Api\Dto\PhotoAlbum;
use PHPUnit\Framework\TestCase;

final class PhotoAlbumTest extends TestCase
{
    /** A `/photo/albums` row with a cover and two photos. */
    private function albumRow(): array
    {
        return [
            'id' => md5('2023-11-14'),
            'date' => '2023-11-14',
            'photo_count' => 2,
            'cover_photo' => [
                'id' => 'p1',
                'name' => 'IMG_0001.jpg',
                'thumbnail_url' => '/api/v1/photo/photos/p1/thumbnail?sig=cover',
                'full_url' => '/api/v1/photo/photos/p1/full?sig=cover',
            ],
            'photos' => [
                ['id' => 'p1', 'name' => 'IMG_0001.jpg', 'thumbnail_url' => '/t/p1', 'full_url' => '/f/p1'],
                ['id' => 'p2', 'name' => 'IMG_0002.jpg', 'thumbnail_url' => '/t/p2', 'full_url' => '/f/p2'],
            ],
        ];
    }

    public function testMapsIdDateCountCoverAndPhotos(): void
    {
        $album = PhotoAlbum::fromArray($this->albumRow());

        self::assertSame(md5('2023-11-14'), $album->id);
        self::assertSame('2023-11-14', $album->date);
        self::assertSame(2, $album->photoCount);

        self::assertInstanceOf(Photo::class, $album->coverPhoto);
        self::assertSame('p1', $album->coverPhoto->id);
        self::assertSame('/api/v1/photo/photos/p1/thumbnail?sig=cover', $album->coverPhoto->thumbnailUrl);

        self::assertContainsOnlyInstancesOf(Photo::class, $album->photos);
        self::assertCount(2, $album->photos);
        self::assertSame('p1', $album->photos[0]->id);
        self::assertSame('p2', $album->photos[1]->id);
    }

    public function testCoverPhotoIsNullWhenAbsent(): void
    {
        $row = $this->albumRow();
        unset($row['cover_photo']);

        $album = PhotoAlbum::fromArray($row);

        self::assertNull($album->coverPhoto);
    }

    public function testCoverPhotoIsNullWhenNotAnArray(): void
    {
        $row = $this->albumRow();
        $row['cover_photo'] = null;

        self::assertNull(PhotoAlbum::fromArray($row)->coverPhoto);

        $row['cover_photo'] = 'garbage';
        self::assertNull(PhotoAlbum::fromArray($row)->coverPhoto);
    }

    public function testNonArrayPhotoRowsAreSkipped(): void
    {
        $album = PhotoAlbum::fromArray([
            'id' => 'a1',
            'date' => '2023-01-01',
            'photos' => [
                ['id' => 'p1', 'name' => 'a.jpg'],
                'garbage',
                ['id' => 'p2', 'name' => 'b.jpg'],
            ],
        ]);

        self::assertCount(2, $album->photos, 'non-array rows are skipped');
        self::assertSame('p1', $album->photos[0]->id);
        self::assertSame('p2', $album->photos[1]->id);
    }

    public function testPhotoCountFallsBackToCountOfPhotosWhenKeyAbsent(): void
    {
        $album = PhotoAlbum::fromArray([
            'id' => 'a1',
            'date' => '2023-01-01',
            'photos' => [
                ['id' => 'p1', 'name' => 'a.jpg'],
                ['id' => 'p2', 'name' => 'b.jpg'],
                ['id' => 'p3', 'name' => 'c.jpg'],
            ],
        ]);

        self::assertSame(3, $album->photoCount, 'absent photo_count falls back to count(photos)');
    }

    public function testExplicitZeroPhotoCountIsPreserved(): void
    {
        // An explicit 0 must NOT be overwritten by count(photos).
        $album = PhotoAlbum::fromArray([
            'id' => 'a1',
            'date' => '2023-01-01',
            'photo_count' => 0,
            'photos' => [
                ['id' => 'p1', 'name' => 'a.jpg'],
            ],
        ]);

        self::assertSame(0, $album->photoCount, 'explicit 0 is preserved over count(photos)');
    }

    public function testMissingIdAndDateBecomeEmptyStrings(): void
    {
        $album = PhotoAlbum::fromArray([]);

        self::assertSame('', $album->id);
        self::assertSame('', $album->date);
        self::assertSame(0, $album->photoCount, 'no photos and no count → 0');
        self::assertNull($album->coverPhoto);
        self::assertSame([], $album->photos);
    }

    public function testPhotosAbsentBecomesEmptyList(): void
    {
        $album = PhotoAlbum::fromArray(['id' => 'a1', 'date' => '2023-01-01', 'photo_count' => 5]);

        self::assertSame([], $album->photos);
        self::assertSame(5, $album->photoCount, 'photo_count is honoured even with no photos array');
    }

    public function testNonArrayPhotosBecomesEmptyList(): void
    {
        $album = PhotoAlbum::fromArray(['id' => 'a1', 'date' => '2023-01-01', 'photos' => 'garbage']);

        self::assertSame([], $album->photos);
        self::assertSame(0, $album->photoCount);
    }

    public function testPhotoCountCoercesNumericString(): void
    {
        $album = PhotoAlbum::fromArray(['id' => 'a1', 'date' => '2023-01-01', 'photo_count' => '7']);

        self::assertSame(7, $album->photoCount);
    }
}

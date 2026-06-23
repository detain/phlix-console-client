<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\PhotoExif;
use PHPUnit\Framework\TestCase;

final class PhotoExifTest extends TestCase
{
    /** A full EXIF map covering every known key. */
    private function fullExif(): array
    {
        return [
            'camera_make' => 'Canon',
            'camera_model' => 'EOS R5',
            'lens' => 'RF 24-70mm F2.8',
            'aperture' => 'f/2.8',
            'iso' => 400,
            'shutter_speed' => '1/200',
            'focal_length' => '50mm',
            'width' => 4000,
            'height' => 3000,
            'orientation' => 1,
            'orientation_name' => 'Horizontal',
            'date_taken_unix' => 1_700_000_000,
            'date_taken_formatted' => '2023-11-14 22:13',
            'date_taken_year' => '2023',
            'date_taken_month' => '11',
            'gps_lat' => 51.5074,
            'gps_lng' => -0.1278,
            'gps_alt' => 35.2,
            'gps_display' => '51.5074, -0.1278',
        ];
    }

    public function testMapsEveryFieldWithTheRightType(): void
    {
        $exif = PhotoExif::fromArray($this->fullExif());

        self::assertSame('Canon', $exif->cameraMake);
        self::assertSame('EOS R5', $exif->cameraModel);
        self::assertSame('RF 24-70mm F2.8', $exif->lens);
        self::assertSame('f/2.8', $exif->aperture);
        self::assertSame(400, $exif->iso);
        self::assertSame('1/200', $exif->shutterSpeed);
        self::assertSame('50mm', $exif->focalLength);
        self::assertSame(4000, $exif->width);
        self::assertSame(3000, $exif->height);
        self::assertSame(1, $exif->orientation);
        self::assertSame('Horizontal', $exif->orientationName);
        self::assertSame(1_700_000_000, $exif->dateTakenUnix);
        self::assertSame('2023-11-14 22:13', $exif->dateTakenFormatted);
        self::assertSame('2023', $exif->dateTakenYear);
        self::assertSame('11', $exif->dateTakenMonth);
        self::assertSame(51.5074, $exif->gpsLat);
        self::assertSame(-0.1278, $exif->gpsLng);
        self::assertSame(35.2, $exif->gpsAlt);
        self::assertSame('51.5074, -0.1278', $exif->gpsDisplay);
    }

    public function testCoercesNumericStringsToInts(): void
    {
        // The server can send dimensions/ISO/orientation as numeric strings.
        $exif = PhotoExif::fromArray([
            'iso' => '800',
            'width' => '6000',
            'height' => '4000',
            'orientation' => '6',
            'date_taken_unix' => '1700000000',
        ]);

        self::assertSame(800, $exif->iso);
        self::assertSame(6000, $exif->width);
        self::assertSame(4000, $exif->height);
        self::assertSame(6, $exif->orientation);
        self::assertSame(1_700_000_000, $exif->dateTakenUnix);
    }

    public function testCoercesNumericGpsStringsToFloats(): void
    {
        $exif = PhotoExif::fromArray([
            'gps_lat' => '51.5074',
            'gps_lng' => '-0.1278',
            'gps_alt' => '35',
        ]);

        self::assertSame(51.5074, $exif->gpsLat);
        self::assertSame(-0.1278, $exif->gpsLng);
        self::assertSame(35.0, $exif->gpsAlt);
    }

    public function testAcceptsNumericAperatureShutterAndFocalAsStrings(): void
    {
        // These can arrive as bare numbers; carried as ?string either way.
        $exif = PhotoExif::fromArray([
            'aperture' => 2.8,
            'shutter_speed' => 0.005,
            'focal_length' => 50,
        ]);

        self::assertSame('2.8', $exif->aperture);
        self::assertSame('0.005', $exif->shutterSpeed);
        self::assertSame('50', $exif->focalLength);
    }

    public function testMissingKeysBecomeNull(): void
    {
        $exif = PhotoExif::fromArray([]);

        self::assertNull($exif->cameraMake);
        self::assertNull($exif->cameraModel);
        self::assertNull($exif->lens);
        self::assertNull($exif->aperture);
        self::assertNull($exif->iso);
        self::assertNull($exif->shutterSpeed);
        self::assertNull($exif->focalLength);
        self::assertNull($exif->width);
        self::assertNull($exif->height);
        self::assertNull($exif->orientation);
        self::assertNull($exif->orientationName);
        self::assertNull($exif->dateTakenUnix);
        self::assertNull($exif->dateTakenFormatted);
        self::assertNull($exif->dateTakenYear);
        self::assertNull($exif->dateTakenMonth);
        self::assertNull($exif->gpsLat);
        self::assertNull($exif->gpsLng);
        self::assertNull($exif->gpsAlt);
        self::assertNull($exif->gpsDisplay);
    }

    public function testIsEmptyIsTrueForAnEmptyMap(): void
    {
        self::assertTrue(PhotoExif::fromArray([])->isEmpty());
    }

    /**
     * @param array<string,mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('singleFieldProvider')]
    public function testIsEmptyIsFalseWithAnySingleField(array $data): void
    {
        self::assertFalse(PhotoExif::fromArray($data)->isEmpty());
    }

    /**
     * @return iterable<string, array{0: array<string,mixed>}>
     */
    public static function singleFieldProvider(): iterable
    {
        yield 'camera_make' => [['camera_make' => 'Nikon']];
        yield 'iso' => [['iso' => 100]];
        yield 'width' => [['width' => 1024]];
        yield 'gps_lat' => [['gps_lat' => 1.5]];
        yield 'gps_display' => [['gps_display' => '1.5, 2.5']];
        yield 'date_taken_unix' => [['date_taken_unix' => 1]];
    }

    public function testDisplayPairsReturnsOnlyPresentFieldsInOrderWithLabels(): void
    {
        $pairs = PhotoExif::fromArray($this->fullExif())->displayPairs();

        self::assertSame([
            ['Camera', 'Canon EOS R5'],
            ['Lens', 'RF 24-70mm F2.8'],
            ['Aperture', 'f/2.8'],
            ['ISO', '400'],
            ['Shutter', '1/200'],
            ['Focal', '50mm'],
            ['Dimensions', '4000 × 3000'],
            ['Taken', '2023-11-14 22:13'],
            ['GPS', '51.5074, -0.1278'],
        ], $pairs);
    }

    public function testDisplayPairsIsEmptyWhenNothingIsKnown(): void
    {
        self::assertSame([], PhotoExif::fromArray([])->displayPairs());
    }

    public function testDisplayPairsCameraJoinsMakeAndModel(): void
    {
        $pairs = PhotoExif::fromArray(['camera_make' => 'Sony', 'camera_model' => 'A7 IV'])->displayPairs();

        self::assertSame([['Camera', 'Sony A7 IV']], $pairs);
    }

    public function testDisplayPairsCameraUsesEitherPartAlone(): void
    {
        self::assertSame(
            [['Camera', 'Fujifilm']],
            PhotoExif::fromArray(['camera_make' => 'Fujifilm'])->displayPairs(),
        );
        self::assertSame(
            [['Camera', 'X100V']],
            PhotoExif::fromArray(['camera_model' => 'X100V'])->displayPairs(),
        );
    }

    public function testDisplayPairsOmitsDimensionsUnlessBothPresent(): void
    {
        // width only → no Dimensions pair.
        self::assertSame([], PhotoExif::fromArray(['width' => 4000])->displayPairs());
        // height only → no Dimensions pair.
        self::assertSame([], PhotoExif::fromArray(['height' => 3000])->displayPairs());
        // both → the W × H pair.
        self::assertSame(
            [['Dimensions', '4000 × 3000']],
            PhotoExif::fromArray(['width' => 4000, 'height' => 3000])->displayPairs(),
        );
    }

    public function testDisplayPairsRendersIsoAsAString(): void
    {
        self::assertSame([['ISO', '1600']], PhotoExif::fromArray(['iso' => 1600])->displayPairs());
    }

    public function testDisplayPairsOmitsNullGpsDisplayEvenWithCoordinates(): void
    {
        // The numeric GPS coordinates are carried but only gps_display is shown.
        $pairs = PhotoExif::fromArray(['gps_lat' => 51.5, 'gps_lng' => -0.1])->displayPairs();

        self::assertSame([], $pairs, 'no gps_display string → no GPS pair');
    }
}

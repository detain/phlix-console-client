<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * EXIF metadata for a photo, as the server's ExifProvider exposes it.
 *
 * Every field is OPTIONAL (present only when the corresponding tag is known),
 * so each maps to a nullable property. The server shapes `aperture`,
 * `shutterSpeed` and `focalLength` as either pre-formatted strings (e.g.
 * `"f/2.8"`, `"1/200"`, `"50mm"`) or bare numbers depending on the source, so
 * they are carried as `?string` (any scalar coerces cleanly). Dimensions and
 * ISO/orientation are integers; GPS coordinates are floats. Immutable.
 *
 * Only the photo DETAIL shape (`/photo/photos/{id}`) carries EXIF; the album
 * and list rows do not, so {@see Photo} leaves its `exif` null there.
 */
final readonly class PhotoExif
{
    public function __construct(
        public ?string $cameraMake,
        public ?string $cameraModel,
        public ?string $lens,
        public ?string $aperture,
        public ?int $iso,
        public ?string $shutterSpeed,
        public ?string $focalLength,
        public ?int $width,
        public ?int $height,
        public ?int $orientation,
        public ?string $orientationName,
        public ?int $dateTakenUnix,
        public ?string $dateTakenFormatted,
        public ?string $dateTakenYear,
        public ?string $dateTakenMonth,
        public ?float $gpsLat,
        public ?float $gpsLng,
        public ?float $gpsAlt,
        public ?string $gpsDisplay,
    ) {
    }

    /**
     * Build from the server's raw EXIF map, coercing each known key through the
     * helper that matches its declared type. Absent keys become null.
     *
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            cameraMake: Coerce::nstr($data['camera_make'] ?? null),
            cameraModel: Coerce::nstr($data['camera_model'] ?? null),
            lens: Coerce::nstr($data['lens'] ?? null),
            aperture: Coerce::nstr($data['aperture'] ?? null),
            iso: Coerce::nint($data['iso'] ?? null),
            shutterSpeed: Coerce::nstr($data['shutter_speed'] ?? null),
            focalLength: Coerce::nstr($data['focal_length'] ?? null),
            width: Coerce::nint($data['width'] ?? null),
            height: Coerce::nint($data['height'] ?? null),
            orientation: Coerce::nint($data['orientation'] ?? null),
            orientationName: Coerce::nstr($data['orientation_name'] ?? null),
            dateTakenUnix: Coerce::nint($data['date_taken_unix'] ?? null),
            dateTakenFormatted: Coerce::nstr($data['date_taken_formatted'] ?? null),
            dateTakenYear: Coerce::nstr($data['date_taken_year'] ?? null),
            dateTakenMonth: Coerce::nstr($data['date_taken_month'] ?? null),
            gpsLat: Coerce::nfloat($data['gps_lat'] ?? null),
            gpsLng: Coerce::nfloat($data['gps_lng'] ?? null),
            gpsAlt: Coerce::nfloat($data['gps_alt'] ?? null),
            gpsDisplay: Coerce::nstr($data['gps_display'] ?? null),
        );
    }

    /** True when no EXIF field is known (every property is null). */
    public function isEmpty(): bool
    {
        return $this->cameraMake === null
            && $this->cameraModel === null
            && $this->lens === null
            && $this->aperture === null
            && $this->iso === null
            && $this->shutterSpeed === null
            && $this->focalLength === null
            && $this->width === null
            && $this->height === null
            && $this->orientation === null
            && $this->orientationName === null
            && $this->dateTakenUnix === null
            && $this->dateTakenFormatted === null
            && $this->dateTakenYear === null
            && $this->dateTakenMonth === null
            && $this->gpsLat === null
            && $this->gpsLng === null
            && $this->gpsAlt === null
            && $this->gpsDisplay === null;
    }

    /**
     * Ordered label → value pairs for the NON-null fields only, ready for an
     * EXIF detail panel. Camera joins make + model; Dimensions is rendered as
     * `W × H` only when both are present; every pair whose value is null/empty
     * is omitted.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function displayPairs(): array
    {
        $pairs = [];

        $camera = $this->cameraLabel();
        if ($camera !== null) {
            $pairs[] = ['Camera', $camera];
        }
        if ($this->lens !== null) {
            $pairs[] = ['Lens', $this->lens];
        }
        if ($this->aperture !== null) {
            $pairs[] = ['Aperture', $this->aperture];
        }
        if ($this->iso !== null) {
            $pairs[] = ['ISO', (string) $this->iso];
        }
        if ($this->shutterSpeed !== null) {
            $pairs[] = ['Shutter', $this->shutterSpeed];
        }
        if ($this->focalLength !== null) {
            $pairs[] = ['Focal', $this->focalLength];
        }
        if ($this->width !== null && $this->height !== null) {
            $pairs[] = ['Dimensions', $this->width . ' × ' . $this->height];
        }
        if ($this->dateTakenFormatted !== null) {
            $pairs[] = ['Taken', $this->dateTakenFormatted];
        }
        if ($this->gpsDisplay !== null) {
            $pairs[] = ['GPS', $this->gpsDisplay];
        }

        return $pairs;
    }

    /** The "Make Model" label, either part alone, or null when neither known. */
    private function cameraLabel(): ?string
    {
        $parts = array_values(array_filter(
            [$this->cameraMake, $this->cameraModel],
            static fn (?string $part): bool => $part !== null && $part !== '',
        ));

        return $parts === [] ? null : implode(' ', $parts);
    }
}

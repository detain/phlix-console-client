<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A single photo in a `photo`-type library.
 *
 * Both server shapes carry the SIGNED `thumbnail_url`/`full_url` (which, unlike
 * the audiobook/book covers, load DIRECTLY — they are short-lived signed
 * endpoints). The DETAIL shape (`/photo/photos/{id}`) additionally carries an
 * `exif` map (full EXIF metadata); the album/list rows do not, so `exif` is
 * null when mapping those — the grid only needs the thumbnail there. Immutable.
 */
final readonly class Photo
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $thumbnailUrl,
        public ?string $fullUrl,
        public ?PhotoExif $exif,
    ) {
    }

    /**
     * Build from either an album/list row (no `exif`) or a detail row (with the
     * full `exif` map). The signed thumbnail/full URLs are present in both.
     *
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            name: Coerce::nstr($data['name'] ?? null) ?? '',
            thumbnailUrl: Coerce::nstr($data['thumbnail_url'] ?? null),
            fullUrl: Coerce::nstr($data['full_url'] ?? null),
            exif: isset($data['exif']) && is_array($data['exif'])
                ? PhotoExif::fromArray($data['exif'])
                : null,
        );
    }
}

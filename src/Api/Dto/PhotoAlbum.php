<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A date-grouped photo album, as the server's `/photo/albums` endpoint returns
 * it (albums are sorted date-descending; each `id` is an md5 of the date key).
 *
 * Every album carries its full `photos` list (so the slideshow can cycle them
 * client-side with no extra round-trip) plus an optional `cover_photo`. When
 * the server omits `photo_count`, it falls back to the count of mapped photos.
 * Immutable.
 */
final readonly class PhotoAlbum
{
    /**
     * @param list<Photo> $photos
     */
    public function __construct(
        public string $id,
        public string $date,
        public int $photoCount,
        public ?Photo $coverPhoto,
        public array $photos,
    ) {
    }

    /**
     * Build from a `/photo/albums` row. Non-array `photos` rows are skipped;
     * `photo_count` falls back to the mapped photo count only when the key is
     * absent (an explicit 0 is preserved).
     *
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $photos = [];
        foreach (Coerce::map($data['photos'] ?? null) as $row) {
            if (is_array($row)) {
                $photos[] = Photo::fromArray($row);
            }
        }

        $cover = $data['cover_photo'] ?? null;

        return new self(
            id: Coerce::str($data['id'] ?? ''),
            date: Coerce::nstr($data['date'] ?? null) ?? '',
            photoCount: array_key_exists('photo_count', $data)
                ? Coerce::int($data['photo_count'], 0)
                : count($photos),
            coverPhoto: is_array($cover) ? Photo::fromArray($cover) : null,
            photos: $photos,
        );
    }
}

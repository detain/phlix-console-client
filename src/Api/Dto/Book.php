<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A book in a `book`-type library, mirroring the server's two divergent shapes.
 *
 * The list endpoint (`/books`) returns RAW media-item rows whose `title`/`author`
 * live under a nested `metadata` key and which carry NO usable cover URL (the
 * `metadata.cover_path` is a server filesystem path). The detail endpoint
 * (`/books/{id}`) returns the same row plus short-lived SIGNED `cover_url`,
 * `read_url` and `download_url` fields. {@see fromArray()} is tolerant of both:
 * the signed-URL fields are simply null when mapping a list row.
 *
 * `format` is the lowercased file extension derived from the row's `path`
 * (e.g. `epub`/`pdf`/`cbz`), or null when there is none. Immutable.
 */
final readonly class Book
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $author,
        public ?string $coverUrl,
        public ?string $downloadUrl,
        public ?string $readUrl,
        public ?string $format,
    ) {
    }

    /**
     * Build from either a raw list row (title/author under `metadata`, no signed
     * URLs) or a detail row (the same row plus signed cover/read/download URLs).
     *
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $metadata = Coerce::map($data['metadata'] ?? null);

        return new self(
            id: Coerce::str($data['id'] ?? ''),
            title: Coerce::nstr($metadata['title'] ?? null)
                ?? Coerce::nstr($data['name'] ?? null)
                ?? '',
            author: Coerce::nstr($metadata['author'] ?? null),
            coverUrl: Coerce::nstr($data['cover_url'] ?? null),
            downloadUrl: Coerce::nstr($data['download_url'] ?? null),
            readUrl: Coerce::nstr($data['read_url'] ?? null),
            format: self::format($data),
        );
    }

    /**
     * Derive the lowercased file extension from the row's `path`, or null when
     * the path is absent/empty or has no extension.
     *
     * @param array<string,mixed> $data
     */
    private static function format(array $data): ?string
    {
        $path = Coerce::nstr($data['path'] ?? null);
        if ($path === null) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === '' ? null : $extension;
    }
}

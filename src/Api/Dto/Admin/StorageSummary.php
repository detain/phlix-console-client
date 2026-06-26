<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The admin dashboard's storage usage summary (a single object), mirroring
 * `GET /api/v1/admin/dashboard/storage`. Per-type byte totals plus the transcode
 * cache; the server's pre-formatted strings are ignored — the screen humanizes
 * the raw byte counts itself. Tolerant; immutable.
 */
final readonly class StorageSummary
{
    public function __construct(
        public int $movieBytes,
        public int $seriesBytes,
        public int $musicBytes,
        public int $photoBytes,
        public int $transcodeCacheBytes,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            movieBytes: Coerce::int($data['movie_bytes'] ?? 0),
            seriesBytes: Coerce::int($data['series_bytes'] ?? 0),
            musicBytes: Coerce::int($data['music_bytes'] ?? 0),
            photoBytes: Coerce::int($data['photo_bytes'] ?? 0),
            transcodeCacheBytes: Coerce::int($data['transcode_cache_bytes'] ?? 0),
        );
    }

    /** The summed media bytes across every type (excludes the transcode cache). */
    public function mediaTotalBytes(): int
    {
        return $this->movieBytes + $this->seriesBytes + $this->musicBytes + $this->photoBytes;
    }

    /** The grand total: all media plus the transcode cache. */
    public function totalBytes(): int
    {
        return $this->mediaTotalBytes() + $this->transcodeCacheBytes;
    }
}

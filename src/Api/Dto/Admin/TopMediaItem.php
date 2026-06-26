<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One row of the admin dashboard's Top Media list, mirroring an item of
 * `GET /api/v1/admin/dashboard/top-media`. Tolerant; immutable.
 */
final readonly class TopMediaItem
{
    public function __construct(
        public string $mediaItemId,
        public ?string $title,
        public ?string $type,
        public int $playCount,
        public int $totalDuration,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mediaItemId: Coerce::str($data['media_item_id'] ?? ''),
            title: Coerce::nstr($data['title'] ?? null),
            type: Coerce::nstr($data['type'] ?? null),
            playCount: Coerce::int($data['play_count'] ?? 0),
            totalDuration: Coerce::int($data['total_duration'] ?? 0),
        );
    }

    /** A display label for the item: the title, falling back to the media id. */
    public function label(): string
    {
        return $this->title ?? ($this->mediaItemId !== '' ? $this->mediaItemId : 'Unknown');
    }
}

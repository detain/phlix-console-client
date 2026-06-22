<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A page of media items, mirroring `GET /api/v1/media`'s
 * `{items, total, offset, limit}` shape. Immutable.
 */
final readonly class MediaPage
{
    /**
     * @param list<MediaItem> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $offset,
        public int $limit,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach (Coerce::map($data['items'] ?? null) as $row) {
            if (is_array($row)) {
                $items[] = MediaItem::fromArray($row);
            }
        }

        $count = count($items);

        return new self(
            items: $items,
            total: Coerce::int($data['total'] ?? $count, $count),
            offset: Coerce::int($data['offset'] ?? 0),
            limit: Coerce::int($data['limit'] ?? $count, $count),
        );
    }

    /** Whether more items exist beyond this page. */
    public function hasMore(): bool
    {
        return $this->offset + count($this->items) < $this->total;
    }
}

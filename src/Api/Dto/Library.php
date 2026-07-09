<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A media library, mirroring the server's `GET /api/v1/libraries` shape
 * (each row plus the router-added `item_count`). Immutable.
 */
final readonly class Library
{
    /**
     * @param list<string>        $paths
     * @param array<string,mixed> $options
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public array $paths,
        public array $options,
        public int $displayOrder,
        public int $itemCount,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            type: Coerce::str($data['type'] ?? ''),
            paths: Coerce::stringList($data['paths'] ?? []),
            options: Coerce::map($data['options'] ?? null),
            displayOrder: Coerce::int($data['display_order'] ?? 0),
            itemCount: Coerce::int($data['item_count'] ?? 0),
            createdAt: Coerce::nstr($data['created_at'] ?? null),
            updatedAt: Coerce::nstr($data['updated_at'] ?? null),
        );
    }
}

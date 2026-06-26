<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * One entry of the admin log file list, mirroring an item of
 * `GET /api/v1/admin/logs` → `{files: [{name, size, modified_at}]}`. The size is
 * the file's byte count; `modifiedAt` is the server's ISO-8601 string (rendered
 * as-is). Tolerant; immutable.
 */
final readonly class LogFile
{
    public function __construct(
        public string $name,
        public int $size,
        public string $modifiedAt,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Coerce::str($data['name'] ?? ''),
            size: Coerce::int($data['size'] ?? 0),
            modifiedAt: Coerce::str($data['modified_at'] ?? ''),
        );
    }
}

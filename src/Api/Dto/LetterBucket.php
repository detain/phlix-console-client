<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * One A–Z jump bucket from `GET /api/v1/media/letter-index`: the first-letter
 * label, the absolute item offset where that letter begins (in name-ascending
 * order), and how many items it holds. Immutable.
 */
final readonly class LetterBucket
{
    public function __construct(
        public string $letter,
        public int $offset,
        public int $count,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            letter: Coerce::str($data['letter'] ?? ''),
            offset: Coerce::int($data['offset'] ?? 0),
            count: Coerce::int($data['count'] ?? 0),
        );
    }

    public function isEmpty(): bool
    {
        return $this->count <= 0;
    }
}

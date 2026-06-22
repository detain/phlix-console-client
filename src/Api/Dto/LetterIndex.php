<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * The A–Z jump index for a filtered media list (`GET /api/v1/media/letter-index`),
 * mirroring `{letters: [{letter, offset, count}], total}`. Buckets arrive in
 * name-ascending order — `#` (non-alphabetic) first, then `A`–`Z` — and every
 * bucket is present, empty ones carrying `count: 0`. Only meaningful for the
 * default name-ascending sort, which is the sort the rail is gated on.
 */
final readonly class LetterIndex
{
    /**
     * @param list<LetterBucket> $letters
     */
    public function __construct(
        public array $letters,
        public int $total,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $letters = [];
        foreach (Coerce::map($data['letters'] ?? null) as $row) {
            if (is_array($row)) {
                $letters[] = LetterBucket::fromArray($row);
            }
        }

        return new self($letters, Coerce::int($data['total'] ?? 0));
    }

    /** The absolute offset where a letter begins, or null when not present. */
    public function offsetFor(string $letter): ?int
    {
        foreach ($this->letters as $bucket) {
            if ($bucket->letter === $letter) {
                return $bucket->offset;
            }
        }

        return null;
    }

    /**
     * The letters that actually have items behind them (the rest are disabled in
     * the rail).
     *
     * @return list<string>
     */
    public function enabledLetters(): array
    {
        $out = [];
        foreach ($this->letters as $bucket) {
            if ($bucket->count > 0) {
                $out[] = $bucket->letter;
            }
        }

        return $out;
    }

    /**
     * Which bucket an absolute item index falls into — used to highlight the
     * current letter as the grid scrolls. Null when out of range.
     */
    public function letterAt(int $index): ?string
    {
        foreach ($this->letters as $bucket) {
            if ($bucket->count > 0 && $index >= $bucket->offset && $index < $bucket->offset + $bucket->count) {
                return $bucket->letter;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->total <= 0;
    }
}

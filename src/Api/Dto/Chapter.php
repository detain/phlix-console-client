<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A named chapter span (seconds) — drives the scrubber's chapter ticks.
 * Immutable.
 */
final readonly class Chapter
{
    public function __construct(
        public float $start,
        public float $end,
        public string $title,
    ) {
    }

    /**
     * @param array<string,mixed> $data `{start_seconds, end_seconds, title}`
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Coerce::float($data['start_seconds'] ?? 0),
            Coerce::float($data['end_seconds'] ?? 0),
            Coerce::str($data['title'] ?? ''),
        );
    }
}

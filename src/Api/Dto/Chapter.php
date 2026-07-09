<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
     * @param array<array-key,mixed> $data `{start_seconds, end_seconds, title}`
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

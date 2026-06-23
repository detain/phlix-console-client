<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A time window in a media item — an intro or outro segment (seconds). Used to
 * offer an in-range "skip" and to tick the scrubber. Immutable.
 */
final readonly class Marker
{
    public function __construct(
        public float $start,
        public float $end,
    ) {
    }

    /**
     * Build from `{start_seconds, end_seconds}`, or null when the marker is
     * absent (the server sends `intro_marker`/`outro_marker` as null).
     *
     * @param array<string,mixed>|null $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        return new self(
            Coerce::float($data['start_seconds'] ?? 0),
            Coerce::float($data['end_seconds'] ?? 0),
        );
    }

    /** Whether $seconds falls within [start, end) — i.e. a skip applies now. */
    public function contains(float $seconds): bool
    {
        return $seconds >= $this->start && $seconds < $this->end;
    }
}

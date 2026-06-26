<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * A single audiobook chapter, mirroring the server's
 * `{index, title, start_ms, end_ms, duration_ms}` shape.
 *
 * The dedicated `/audiobooks/{id}/chapters` endpoint sends a formatted list
 * (each row carrying its own `index`), while the raw `chapters` nested in the
 * `/audiobooks/{id}` detail carry no index — so {@see fromArray()} takes an
 * `$ordinal` to fall back to. All time fields are MILLISECONDS (not seconds or
 * ticks). Immutable.
 */
final readonly class AudiobookChapter
{
    public function __construct(
        public int $index,
        public string $title,
        public int $startMs,
        public int $endMs,
        public int $durationMs,
    ) {
    }

    /**
     * Build from a chapter row, falling back to `$ordinal` for the index when
     * the row omits it (the raw detail chapters do) and to `"Chapter N"` for a
     * missing title (1-based off the resolved index).
     *
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data, int $ordinal): self
    {
        $index = Coerce::nint($data['index'] ?? null) ?? $ordinal;

        return new self(
            index: $index,
            title: Coerce::nstr($data['title'] ?? null) ?? ('Chapter ' . ($index + 1)),
            startMs: Coerce::int($data['start_ms'] ?? null, 0),
            endMs: Coerce::int($data['end_ms'] ?? null, 0),
            durationMs: Coerce::int($data['duration_ms'] ?? null, 0),
        );
    }

    /**
     * A human duration — `m:ss`, or `h:mm:ss` once an hour or longer — computed
     * from the millisecond duration. Empty string when the duration is unknown
     * (which for a chapter means zero, rendered `0:00`).
     */
    public function durationLabel(): string
    {
        $total = intdiv(max(0, $this->durationMs), 1000);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $seconds = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%d:%02d', $minutes, $seconds);
    }
}

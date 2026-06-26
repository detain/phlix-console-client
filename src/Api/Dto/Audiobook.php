<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * An audiobook, mirroring the server's two divergent shapes.
 *
 * The list endpoint (`/audiobooks`) returns RAW media-item rows whose
 * author/narrator/series/etc. live under a nested `metadata` key, while the
 * detail endpoint (`/audiobooks/{id}`) returns a FLATTENED row with those
 * fields hoisted to the top level (and a `title` already resolved from `name`)
 * plus a short-lived SIGNED `stream_url`. {@see fromArray()} is tolerant of
 * both: it prefers the flat top-level key and falls back to `metadata.<key>`,
 * and `streamUrl` is simply null when mapping a list row.
 *
 * `durationMs` is MILLISECONDS (the server's `duration_ms`), not seconds or
 * ticks. The server's `cover_url` is a raw filesystem path (a server bug) and
 * is therefore deliberately NOT exposed here — audiobooks are text-forward,
 * exactly as {@see Book} omits the equally-unusable `metadata.cover_path`.
 * Immutable.
 */
final readonly class Audiobook
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $author,
        public ?string $narrator,
        public ?string $series,
        public ?int $seriesPosition,
        public ?string $description,
        public ?int $durationMs,
        public ?string $language,
        public ?string $streamUrl,
    ) {
    }

    /**
     * Build from either a raw list row (fields under `metadata`, no signed URL)
     * or a flat detail row (fields hoisted, plus the signed `stream_url`).
     *
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $metadata = Coerce::map($data['metadata'] ?? null);

        return new self(
            id: Coerce::str($data['id'] ?? ''),
            title: Coerce::nstr($data['title'] ?? null)
                ?? Coerce::nstr($metadata['title'] ?? null)
                ?? Coerce::nstr($data['name'] ?? null)
                ?? '',
            author: Coerce::nstr($data['author'] ?? null)
                ?? Coerce::nstr($metadata['author'] ?? null),
            narrator: Coerce::nstr($data['narrator'] ?? null)
                ?? Coerce::nstr($metadata['narrator'] ?? null),
            series: Coerce::nstr($data['series'] ?? null)
                ?? Coerce::nstr($metadata['series'] ?? null),
            seriesPosition: Coerce::nint($data['series_position'] ?? null)
                ?? Coerce::nint($metadata['series_position'] ?? null),
            description: Coerce::nstr($data['description'] ?? null)
                ?? Coerce::nstr($metadata['description'] ?? null),
            durationMs: Coerce::nint($data['duration_ms'] ?? null)
                ?? Coerce::nint($metadata['duration_ms'] ?? null),
            language: Coerce::nstr($data['language'] ?? null)
                ?? Coerce::nstr($metadata['language'] ?? null),
            streamUrl: Coerce::nstr($data['stream_url'] ?? null),
        );
    }

    /**
     * A human duration — `m:ss`, or `h:mm:ss` once an hour or longer — computed
     * from the millisecond duration. Empty string when the duration is unknown.
     */
    public function durationLabel(): string
    {
        if ($this->durationMs === null) {
            return '';
        }

        $total = intdiv(max(0, $this->durationMs), 1000);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $seconds = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%d:%02d', $minutes, $seconds);
    }
}

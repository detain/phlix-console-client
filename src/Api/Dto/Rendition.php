<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * One rung of the server's ABR quality ladder — a single selectable variant.
 *
 * Mirrors the server contract shared by three endpoints:
 *   - `POST /api/v1/media/{id}/transcode`      → `variants: Rendition[]|null`
 *   - `GET  /api/v1/transcode/{jobId}/status`  → `variants: Rendition[]|null`
 *   - `GET  /api/v1/media/{id}/playback-info`  → `quality_ladder: Rendition[]|null`
 *
 * `id` is the lowercase ladder token — normally one of `240p`,`360p`,`480p`,
 * `720p`,`1080p`,`1440p`,`2160p`,`original`, but for a source shorter than the
 * 240p floor the server emits a single source-sized fallback rung whose id is
 * still `{height}p` (e.g. `144p`); treat it as an opaque string. `url` is a
 * SIGNED path to that variant's own
 * `media_v{id}.m3u8` (highest-first in the list); it is `null` for the
 * pre-flight `quality_ladder` preview (no job created yet) and for legacy jobs.
 * A `null` list at the source (legacy / unscanned item) decodes to an empty
 * list here — callers treat "no rungs" as "Auto only". Immutable.
 */
final readonly class Rendition
{
    public function __construct(
        public string $id,
        public string $label,
        public ?int $width,
        public ?int $height,
        public ?int $bitrate,
        public string $codecs,
        public ?string $url,
        public bool $isOriginal,
        public bool $isCopy,
        public ?int $videoBitrate,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            label: Coerce::str($data['label'] ?? ''),
            width: Coerce::nint($data['width'] ?? null),
            height: Coerce::nint($data['height'] ?? null),
            bitrate: Coerce::nint($data['bitrate'] ?? null),
            codecs: Coerce::str($data['codecs'] ?? ''),
            url: Coerce::nstr($data['url'] ?? null),
            isOriginal: Coerce::bool($data['is_original'] ?? false),
            isCopy: Coerce::bool($data['is_copy'] ?? false),
            videoBitrate: Coerce::nint($data['video_bitrate'] ?? null),
        );
    }

    /**
     * Decode a `variants` / `quality_ladder` field into a list of renditions.
     * A `null` field (legacy job / unscanned item) or any non-array entry is
     * dropped, yielding an empty list — never a crash or a confusing null rung.
     *
     * @return list<self>
     */
    public static function listFromArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = self::fromArray($row);
            }
        }

        return $out;
    }

    /**
     * A human display label for the menu, falling back to the id when the
     * server sent no explicit label (e.g. a bare `quality_ladder` preview).
     */
    public function displayLabel(): string
    {
        return $this->label !== '' ? $this->label : $this->id;
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A subtitle track from the media_streams table (stream_type = 'subtitle').
 *
 * Mirrors the server's `media_streams` row where `stream_type = 'subtitle'`.
 * Language is a BCP 47 tag (e.g., "en-US", "ja-JP", "de-DE").
 *
 * From `GET /api/v1/media/{id}/playback-info` → `subtitle_tracks: StreamSubtitleTrack[]`.
 *
 * @see https://github.com/phlix/phlix-contracts/blob/v0.3.2/src/SubtitleTrack.ts
 */
final readonly class StreamSubtitleTrack
{
    public function __construct(
        public string $id,
        public string $codec,
        /** BCP 47 language tag (e.g., "en-US", "es-ES"). */
        public string $language,
        /** Track title (e.g., "English (SDH)", "Spanish"). */
        public ?string $title = null,
        /** Whether this is a forced subtitle track (auto-displayed for foreign audio). */
        public bool $isForced = false,
        /** Whether this is the default subtitle track. */
        public bool $isDefault = false,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            codec: Coerce::str($data['codec'] ?? ''),
            language: Coerce::str($data['language'] ?? 'und', 'und'),
            title: Coerce::nstr($data['title'] ?? null),
            isForced: Coerce::bool($data['is_forced'] ?? false),
            isDefault: Coerce::bool($data['is_default'] ?? false),
        );
    }

    /**
     * Decode a `subtitle_tracks` field into a list of subtitle tracks.
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
     * A human display label for the menu.
     */
    public function displayLabel(): string
    {
        $label = $this->language;
        if ($this->title !== null && $this->title !== '') {
            $label .= ' - ' . $this->title;
        }
        if ($this->isForced) {
            $label .= ' [forced]';
        }

        return $label;
    }
}

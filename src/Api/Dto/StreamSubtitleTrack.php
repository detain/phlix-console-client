<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A subtitle track element of `subtitle_tracks[]` on
 * `GET /api/v1/media/{id}/playback-info` (and its `/playback` twin, which share
 * the server's `Phlix\Media\Library\StreamTrackShaper::subtitleTracks()`).
 *
 * S404 authority ruling (contracts `v0.4.5`, verified against the shaper at
 * server `01340633`): the per-track WIRE keys are
 * `{id, index, stream_index, language, label, codec, source,
 * hearing_impaired, url}`. The server derives the display string as
 * `label = title ?? language ?? 'Subtitle N'` — the wire NEVER carries
 * `title`, `is_forced` or `is_default` on subtitles (the pre-S404 version of
 * this DTO parsed all three; none was ever populated; its docblock claimed the
 * opposite). There is no forced/default CONCEPT on the subtitle wire at all —
 * `hearing_impaired` is the only flag it carries.
 *
 * This DTO mirrors the display-relevant SUBSET of the wire row:
 * `index`/`stream_index` (ffmpeg selector ordinals) and the signed `url` are
 * deliberately not modelled here — the console fetches caption payloads via
 * the separate `/api/v1/media/{id}/subtitles` rail through the distinct
 * {@see SubtitleTrack} DTO, not from these playback rows.
 *
 * @see https://github.com/detain/phlix-contracts/blob/v0.4.5/src/playback.ts (wire TS twin)
 * @see phlix-server src/Media/Library/StreamTrackShaper.php (authoritative emission)
 */
final readonly class StreamSubtitleTrack
{
    public function __construct(
        public string $id,
        public string $codec,
        /** BCP 47 language tag (e.g., "en-US", "es-ES"); the server coerces `'und'`. */
        public string $language,
        /** Server-derived display string (`title ?? language ?? 'Subtitle N'`); non-empty on the wire. */
        public string $label,
        /** Provenance for downloaded external rows (`media_streams.storage_path` rows); `null` for embedded rows. */
        public ?string $source = null,
        /** Stored hearing-impaired flag (wire `hearing_impaired`). */
        public bool $hearingImpaired = false,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data one `subtitle_tracks[]` wire row
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Coerce::str($data['id'] ?? ''),
            codec: Coerce::str($data['codec'] ?? ''),
            language: Coerce::str($data['language'] ?? 'und', 'und'),
            label: Coerce::str($data['label'] ?? ''),
            source: Coerce::nstr($data['source'] ?? null),
            hearingImpaired: Coerce::bool($data['hearing_impaired'] ?? false),
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
     *
     * Leads with the language, appends the server `label` when it says more
     * than the language already does, and flags hearing-impaired tracks — the
     * wire's only subtitle flag. (Pre-S404 this appended a never-emitted
     * `title` and a never-emitted forced marker.)
     */
    public function displayLabel(): string
    {
        $label = $this->language;
        if ($this->label !== '' && $this->label !== $this->language) {
            $label .= ' - ' . $this->label;
        }
        if ($this->hearingImpaired) {
            $label .= ' [HI]';
        }

        return $label;
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * An audio track from the media_streams table (stream_type = 'audio').
 *
 * Mirrors the server's `media_streams` row where `stream_type = 'audio'`.
 * Language is a BCP 47 tag (e.g., "en-US", "ja-JP", "de-DE").
 *
 * From `GET /api/v1/media/{id}/playback-info` → `audio_tracks: StreamAudioTrack[]`.
 *
 * ⚠ S325b reconciliation note: this mirror matches the SERVER's emission
 * (`StreamTrackShaper::audioTracks()` — keys `title`/`bitrate`, no `url`),
 * while `@phlix/contracts` `AudioTrack` declares `display_title` + `url?` and
 * NO bitrate — the contract and this route's wire shape disagree. The
 * divergence is filed as step S404; do NOT "align" this mirror to the TS
 * interface without checking the server shaper, or the labels go blank the
 * way the tizen parental fields did (S234 family).
 *
 * @see https://github.com/detain/phlix-contracts/blob/v0.4.4/src/AudioTrack.ts
 * @see phlix-server src/Media/Library/StreamTrackShaper.php (authoritative emission)
 */
final readonly class StreamAudioTrack
{
    public function __construct(
        public string $id,
        public string $codec,
        /** BCP 47 language tag (e.g., "en-US", "es-ES"). */
        public string $language,
        public int $channels,
        /** Bitrate in bits per second. */
        public ?int $bitrate = null,
        /** Track title (e.g., "Director's Commentary"). */
        public ?string $title = null,
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
            channels: Coerce::int($data['channels'] ?? 0),
            bitrate: Coerce::nint($data['bitrate'] ?? null),
            title: Coerce::nstr($data['title'] ?? null),
        );
    }

    /**
     * Decode an `audio_tracks` field into a list of audio tracks.
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
        $label .= ' (' . $this->channels . ' ch)';

        return $label;
    }
}

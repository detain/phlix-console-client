<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * Playback info for an item, mirroring `GET /api/v1/media/{id}/playback`'s
 * `playback_info` object (sources + skip markers + audio tracks). Immutable.
 *
 * Sources and markers are kept as raw maps for now; the player (Phase 4) will
 * give them dedicated value types once their use is concrete.
 *
 * NOTE: the pre-flight ABR ladder preview (`quality_ladder`) is NOT part of
 * this shape — it is served by the DISTINCT `GET /api/v1/media/{id}/playback-info`
 * route (see {@see \Phlix\Console\Api\Dto\Rendition}), which this DTO does not
 * model. The console's quality picker is driven entirely by a real transcode
 * job's `variants[]` (see {@see \Phlix\Console\Screen\PlayerScreen}), so no
 * pre-flight-ladder field is carried here.
 */
final readonly class PlaybackInfo
{
    /**
     * @param list<array<string,mixed>> $mediaSources
     * @param array<string,mixed>       $markers
     * @param list<StreamAudioTrack>    $audioTracks
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public array $mediaSources,
        public array $markers,
        public array $audioTracks = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sources = [];
        foreach (Coerce::map($data['media_sources'] ?? null) as $source) {
            if (is_array($source)) {
                $sources[] = $source;
            }
        }

        return new self(
            id: Coerce::str($data['id'] ?? ''),
            name: Coerce::str($data['name'] ?? ''),
            type: Coerce::str($data['type'] ?? ''),
            mediaSources: $sources,
            markers: Coerce::map($data['markers'] ?? null),
            audioTracks: StreamAudioTrack::listFromArray($data['audio_tracks'] ?? null),
        );
    }
}

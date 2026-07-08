<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * Playback info for an item, mirroring `GET /api/v1/media/{id}/playback`'s
 * `playback_info` object (sources + skip markers). Immutable.
 *
 * Sources and markers are kept as raw maps for now; the player (Phase 4) will
 * give them dedicated value types once their use is concrete.
 */
final readonly class PlaybackInfo
{
    /**
     * @param list<array<string,mixed>> $mediaSources
     * @param array<string,mixed>       $markers
     * @param list<Rendition>           $qualityLadder pre-flight ABR-ladder preview
     *        (highest-first); every rung's `url` is `null` here (no job created),
     *        so it drives the picker's labels but not playback. Empty when the
     *        item hasn't been scanned with source metadata yet.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public array $mediaSources,
        public array $markers,
        public array $qualityLadder = [],
    ) {
    }

    /**
     * @param array<string,mixed> $data
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
            qualityLadder: Rendition::listFromArray($data['quality_ladder'] ?? null),
        );
    }
}

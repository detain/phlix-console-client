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
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public array $mediaSources,
        public array $markers,
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
        );
    }
}

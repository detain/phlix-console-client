<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto;

/**
 * A selectable text subtitle track, mirroring a row of
 * `GET /api/v1/media/{id}/subtitles`. `index` is the `0:s:{index}` selector used
 * to fetch the track's WebVTT. Immutable.
 */
final readonly class SubtitleTrack
{
    public function __construct(
        public int $index,
        public string $language,
        public string $label,
        public bool $default,
        public string $codec,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            index: Coerce::int($data['index'] ?? 0),
            language: Coerce::str($data['language'] ?? 'und', 'und'),
            label: Coerce::str($data['label'] ?? ''),
            default: Coerce::bool($data['default'] ?? false),
            codec: Coerce::str($data['codec'] ?? ''),
        );
    }
}

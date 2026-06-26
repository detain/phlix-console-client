<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto;

/**
 * Intro/outro skip markers + chapters for an item, mirroring
 * `GET /api/v1/media/{id}/playback-info` (a flat object, NOT the `playback_info`
 * envelope of `/playback`). Drives the scrubber's chapter ticks and the
 * contextual "Skip Intro / Skip Outro" prompt. Immutable.
 */
final readonly class PlaybackMarkers
{
    /**
     * @param list<Chapter> $chapters
     */
    public function __construct(
        public string $itemId,
        public ?Marker $intro,
        public ?Marker $outro,
        public array $chapters,
    ) {
    }

    /** An empty set — the player shows a plain scrubber with no ticks or skips. */
    public static function empty(): self
    {
        return new self('', null, null, []);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $chapters = [];
        foreach (Coerce::map($data['chapters'] ?? null) as $row) {
            if (is_array($row)) {
                $chapters[] = Chapter::fromArray($row);
            }
        }

        return new self(
            Coerce::str($data['item_id'] ?? ''),
            Marker::fromArray(self::nullableMap($data['intro_marker'] ?? null)),
            Marker::fromArray(self::nullableMap($data['outro_marker'] ?? null)),
            $chapters,
        );
    }

    /**
     * The skip window covering $seconds (intro or outro), or null if none —
     * what the player offers to skip with `s`.
     */
    public function activeSkip(float $seconds): ?Marker
    {
        if ($this->intro !== null && $this->intro->contains($seconds)) {
            return $this->intro;
        }
        if ($this->outro !== null && $this->outro->contains($seconds)) {
            return $this->outro;
        }

        return null;
    }

    /** A human label for an active skip window ("Skip Intro" / "Skip Outro"). */
    public function skipLabel(float $seconds): ?string
    {
        if ($this->intro !== null && $this->intro->contains($seconds)) {
            return 'Skip Intro';
        }
        if ($this->outro !== null && $this->outro->contains($seconds)) {
            return 'Skip Outro';
        }

        return null;
    }

    /**
     * A marker object passes through; anything else (null, scalar) becomes null
     * so {@see Marker::fromArray()} yields a null marker.
     *
     * @return array<array-key,mixed>|null
     */
    private static function nullableMap(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}

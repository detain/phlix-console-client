<?php

declare(strict_types=1);

namespace Phlix\Console\Widget;

use Phlix\Console\Api\Dto\MediaItem;

/**
 * One poster tile in a {@see Rail}: a poster area (placeholder until the ANSI
 * is loaded), a title, and an optional progress bar (continue-watching).
 * Immutable; the rendered poster ANSI is attached via {@see withPoster()} when
 * the async load resolves.
 */
final readonly class PosterCard
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $posterUrl = null,
        public ?float $progress = null,
        public ?string $poster = null,
    ) {
    }

    public static function fromMediaItem(MediaItem $item, ?float $progress = null): self
    {
        return new self($item->id, $item->name, $item->posterUrl, $progress);
    }

    public function withPoster(string $ansi): self
    {
        return new self($this->id, $this->title, $this->posterUrl, $this->progress, $ansi);
    }

    public function hasPoster(): bool
    {
        return $this->poster !== null;
    }

    /**
     * Render a fixed-width block: poster (or placeholder) rows, a title row,
     * and a progress row when set. All rows are $width visual cells wide so a
     * rail can stitch cards side by side.
     */
    public function render(bool $focused, int $width, int $posterHeight): string
    {
        $width = max(4, $width);
        $posterHeight = max(1, $posterHeight);

        $lines = $this->poster !== null
            ? explode("\n", $this->poster)
            : array_fill(0, $posterHeight, str_repeat('░', $width));

        $marker = $focused ? '▸' : ' ';
        $lines[] = $this->pad($marker . ' ' . self::truncate($this->title, $width - 2), $width);

        if ($this->progress !== null) {
            $lines[] = $this->pad(self::progressBar($this->progress, $width), $width);
        }

        return implode("\n", $lines);
    }

    private static function truncate(string $text, int $max): string
    {
        $max = max(1, $max);

        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, max(0, $max - 1)) . '…';
    }

    private function pad(string $text, int $width): string
    {
        $len = mb_strlen($text);

        return $len >= $width ? mb_substr($text, 0, $width) : $text . str_repeat(' ', $width - $len);
    }

    private static function progressBar(float $progress, int $width): string
    {
        $progress = max(0.0, min(1.0, $progress));
        $filled = (int) round($progress * $width);

        return str_repeat('▓', $filled) . str_repeat('░', max(0, $width - $filled));
    }
}

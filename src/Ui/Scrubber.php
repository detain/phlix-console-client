<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use Phlix\Console\Api\Dto\Chapter;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;

/**
 * The player's progress bar: `m:ss [████████│░░░░░░░] h:mm:ss`.
 *
 * A hand-rolled immutable widget (matching {@see Sidebar}/{@see FilterBar}/
 * {@see LetterRail} rather than pulling a new dep): the filled portion shows
 * playback position, and chapter boundaries are overlaid as `│` ticks so they
 * read on any terminal (the glyphs carry the meaning, not colour). Progress is
 * glyph-based (`█` vs `░`) so it survives a monochrome / NO_COLOR terminal.
 */
final class Scrubber
{
    private const FILLED = '█';
    private const EMPTY = '░';
    private const TICK = '│';

    /**
     * @param list<Chapter> $chapters
     */
    private function __construct(
        private readonly float $position,
        private readonly float $duration,
        private readonly int $width,
        private readonly array $chapters,
    ) {
    }

    /**
     * @param list<Chapter> $chapters
     */
    public static function of(float $position, float $duration, int $width, array $chapters = []): self
    {
        return new self(max(0.0, $position), max(0.0, $duration), max(8, $width), $chapters);
    }

    public function render(): string
    {
        $pos = self::clock($this->position);
        $dur = $this->duration > 0.0 ? self::clock($this->duration) : '--:--';

        // Bar fills the width minus the two clocks, two surrounding spaces, and
        // the two brackets.
        $barWidth = max(1, $this->width - Width::of($pos) - Width::of($dur) - 4);

        $fraction = $this->duration > 0.0 ? max(0.0, min(1.0, $this->position / $this->duration)) : 0.0;
        $filled = (int) round($fraction * $barWidth);

        $cells = [];
        for ($i = 0; $i < $barWidth; $i++) {
            $cells[$i] = $i < $filled ? self::FILLED : self::EMPTY;
        }
        foreach ($this->chapters as $chapter) {
            $idx = $this->chapterCell($chapter, $barWidth);
            if ($idx !== null) {
                $cells[$idx] = self::TICK;
            }
        }

        $bar = Style::new()->bold()->render(implode('', $cells));

        return sprintf('%s [%s] %s', $pos, $bar, $dur);
    }

    /** The bar cell a chapter boundary maps to, or null if at/outside the ends. */
    private function chapterCell(Chapter $chapter, int $barWidth): ?int
    {
        if ($this->duration <= 0.0 || $chapter->start <= 0.0 || $chapter->start >= $this->duration) {
            return null;
        }
        $idx = (int) round(($chapter->start / $this->duration) * $barWidth);

        return ($idx >= 0 && $idx < $barWidth) ? $idx : null;
    }

    /** Seconds → "m:ss" (or "h:mm:ss" past an hour). */
    private static function clock(float $seconds): string
    {
        $s = max(0, (int) round($seconds));
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }
}

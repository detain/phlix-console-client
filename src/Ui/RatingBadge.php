<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use SugarCraft\Sprinkles\Style;

/**
 * Renders a star-based rating badge for display in the terminal.
 *
 * Shows 5 stars with proportional fill based on a 0-10 score.
 * Full stars: every 2 points (0-2 → 1★, 2-4 → 2★, etc.)
 * Half star: when the fractional part >= 0.5
 *
 * Example outputs:
 *   score 7.5 → "★★★½☆  7.5/10"
 *   score 10  → "★★★★★  10/10"
 *   score null → ""
 */
final readonly class RatingBadge
{
    private const FULL_STAR = '★';
    private const EMPTY_STAR = '☆';
    private const HALF_STAR = '½';

    public function __construct(
        public ?float $score,
    ) {
    }

    public function render(): string
    {
        if ($this->score === null) {
            return '';
        }

        $score = max(0.0, min(10.0, $this->score));
        $stars = $this->buildStarString($score);
        $label = number_format($score, 1) . '/10';

        return $stars . '  ' . $label;
    }

    /**
     * Build the star string with proportional fill.
     */
    private function buildStarString(float $score): string
    {
        $normalizedScore = $score / 2; // Convert 0-10 to 0-5 scale
        $fullStars = (int) floor($normalizedScore);
        $hasHalfStar = ($normalizedScore - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);

        $accent = Style::new()->bold()->fg('#ffcc00');
        $dim = Style::new()->faint();

        $result = $accent->render(str_repeat(self::FULL_STAR, $fullStars));

        if ($hasHalfStar) {
            $result .= $accent->render(self::HALF_STAR);
        }

        $result .= $dim->render(str_repeat(self::EMPTY_STAR, $emptyStars));

        return $result;
    }
}

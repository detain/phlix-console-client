<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;

/**
 * Interactive star rating picker overlay for the terminal.
 *
 * Opened via the 'r' key on the detail screen. Displays 5 stars representing
 * ratings 2-10 (2 points per star) plus a "clear" option at position 0.
 * The user navigates with ←/→ or 1-6 keys (1 = clear, 2-6 = stars),
 * confirms with Enter, and cancels with Escape.
 *
 * Immutable (clone-mutate). The cursor is in [0, 5] where 0 = clear and
 * 1-5 represent stars (rating = cursor * 2).
 */
final class UserRatingPicker
{
    private const MAX_WIDTH = 30;
    private const BACKDROP_DIM = 40;
    private const STAR_MULTIPLIER = 2;
    private const STAR_COUNT = 5;

    /**
     * @param int  $cursor           Index 0-5 (0 = clear, 1-5 = stars giving ratings 2,4,6,8,10)
     * @param ?int $currentUserRating The user's existing rating, or null
     */
    private function __construct(
        private int $cursor,
        private ?int $currentUserRating,
        private int $winWidth,
        private int $winHeight,
    ) {
    }

    /**
     * Open the rating picker, pre-selecting the current user rating if set.
     * Position 0 is "clear"; positions 1-5 are stars (ratings 2, 4, 6, 8, 10).
     */
    public static function open(?int $currentUserRating, int $cols, int $rows): self
    {
        // Cursor 0 = clear, cursor 1-5 = stars
        $cursor = 0;
        if ($currentUserRating !== null && $currentUserRating > 0) {
            // Rating maps to star index: cursor = rating / 2, clamped to [1, 5]
            $cursor = max(1, min(self::STAR_COUNT, (int) ($currentUserRating / self::STAR_MULTIPLIER)));
        }

        [$w, $h] = self::dims($cols, $rows);

        return new self($cursor, $currentUserRating, $w, $h);
    }

    public function left(): self
    {
        $next = clone $this;
        $next->cursor = max(0, $this->cursor - 1);

        return $next;
    }

    public function right(): self
    {
        $next = clone $this;
        $next->cursor = min(self::STAR_COUNT, $this->cursor + 1);

        return $next;
    }

    public function resizedTo(int $cols, int $rows): self
    {
        [$w, $h] = self::dims($cols, $rows);

        $next = clone $this;
        $next->winWidth = $w;
        $next->winHeight = $h;

        return $next;
    }

    /** The rating value for the current cursor position (2, 4, 6, 8, 10), or null if clear is selected. */
    public function selectedRating(): ?int
    {
        if ($this->cursor === 0) {
            return null; // clear
        }

        return $this->cursor * self::STAR_MULTIPLIER;
    }

    /** Whether the user has selected the "clear" option. */
    public function isClearing(): bool
    {
        return $this->cursor === 0;
    }

    /** Whether the current selection differs from the existing user rating. */
    public function hasChanges(): bool
    {
        if ($this->isClearing()) {
            return $this->currentUserRating !== null;
        }

        return $this->currentUserRating === null || $this->selectedRating() !== $this->currentUserRating;
    }

    /** Composite the picker box centred over a sugar-veil dimmed background. */
    public function render(string $background): string
    {
        $box = SugarBoxer::new()->render(
            SugarBoxer::new()->leaf($this->body())->withBorder(true)->withPadding(0)->withTitle(' Rate this title '),
            $this->winWidth,
            $this->winHeight,
        );

        return Veil::new()
            ->withBackdrop(self::BACKDROP_DIM)
            ->composite($box, $background, Position::CENTER, Position::CENTER);
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        $lines = [];
        $lines[] = $this->renderStars();
        $lines[] = '';
        $lines[] = $this->renderHint();

        return implode("\n", $lines);
    }

    private function renderStars(): string
    {
        $accent = Style::new()->bold()->fg('#ffcc00');
        $dim = Style::new()->faint();
        $clearColor = Style::new()->bold()->fg('#ff6666');

        $result = '';
        // Position 0: "✕" (clear/rating removal)
        if ($this->cursor === 0) {
            $result .= Style::new()->reverse()->bold()->fg('#ff6666')->render('✕');
        } else {
            $result .= $clearColor->render('✕');
        }
        $result .= ' ';

        // Positions 1-5: stars representing ratings 2, 4, 6, 8, 10
        for ($i = 1; $i <= self::STAR_COUNT; $i++) {
            if ($i === $this->cursor) {
                $result .= Style::new()->reverse()->bold()->fg('#ffcc00')->render('★');
            } elseif ($i < $this->cursor) {
                $result .= $accent->render('★');
            } else {
                $result .= $dim->render('☆');
            }
        }

        $rating = $this->selectedRating();
        $ratingText = $rating !== null ? "{$rating}/10" : '—';
        $result .= '  ' . Style::new()->bold()->render($ratingText);

        return $result;
    }

    private function renderHint(): string
    {
        $dim = Style::new()->faint();

        return $dim->render('←/→ or 1-6  select    ⏎ confirm    Esc  cancel');
    }

    /**
     * @return array{int, int} [winWidth, winHeight]
     */
    private static function dims(int $cols, int $rows): array
    {
        $w = max(20, min($cols - 8, self::MAX_WIDTH));
        $h = 6; // Stars + blank + hint + border top/bottom

        return [$w, $h];
    }

    // ---- accessors (for tests) ----------------------------------------

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function currentUserRating(): ?int
    {
        return $this->currentUserRating;
    }
}

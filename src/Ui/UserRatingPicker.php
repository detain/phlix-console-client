<?php

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;

/**
 * Interactive star rating picker overlay for the terminal.
 *
 * Opened via the 'R' key on the detail screen. Displays 5 stars representing
 * ratings 1-10 (2 points per star). The user navigates with ←/→ or 1-5 keys,
 * confirms with Enter, and cancels with Escape.
 *
 * Immutable (clone-mutate). The cursor is always in [0, 4] representing
 * which star is currently selected (rating = (cursor + 1) * 2).
 */
final class UserRatingPicker
{
    private const MAX_WIDTH = 30;
    private const BACKDROP_DIM = 40;
    private const RATING_MULTIPLIER = 2;

    /**
     * @param int  $cursor           Star index 0-4 (rating = (cursor + 1) * 2)
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
     */
    public static function open(?int $currentUserRating, int $cols, int $rows): self
    {
        // Cursor represents star index 0-4, which gives rating = (index + 1) * 2
        $cursor = 0;
        if ($currentUserRating !== null) {
            $cursor = max(0, min(4, (int) (($currentUserRating / self::RATING_MULTIPLIER) - 1)));
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
        $next->cursor = min(4, $this->cursor + 1);

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

    /** The rating value for the current cursor position (2, 4, 6, 8, 10). */
    public function selectedRating(): int
    {
        return ($this->cursor + 1) * self::RATING_MULTIPLIER;
    }

    /** Whether the current selection differs from the existing user rating. */
    public function hasChanges(): bool
    {
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

        $result = '';
        for ($i = 0; $i < 5; $i++) {
            if ($i === $this->cursor) {
                $result .= Style::new()->reverse()->bold()->fg('#ffcc00')->render('★');
            } elseif ($i < $this->cursor) {
                $result .= $accent->render('★');
            } else {
                $result .= $dim->render('☆');
            }
        }

        $rating = $this->selectedRating();
        $result .= '  ' . Style::new()->bold()->render((string) $rating . '/10');

        return $result;
    }

    private function renderHint(): string
    {
        $dim = Style::new()->faint();

        return $dim->render('←/→ or 1-5  select    ⏎ confirm    Esc  cancel');
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

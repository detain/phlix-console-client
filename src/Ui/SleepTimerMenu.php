<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use Phlix\Console\Ui\SleepTimer;
use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;

/**
 * The in-player sleep timer picker overlay.
 *
 * Displays preset durations (15, 30, 45, 60, 90, 120 minutes) in a single-column
 * menu, plus a "Cancel" option to dismiss without starting. Composited as a
 * bordered box centred over a sugar-veil dimmed backdrop, mirroring the
 * {@see AudioTrackList} overlay pattern.
 *
 * Immutable (clone-mutate). The cursor never leaves [0, count(presets)].
 */
final class SleepTimerMenu
{
    private const MAX_WIDTH = 36;
    private const MIN_WIDTH = 18;
    private const BACKDROP_DIM = 40;

    private const PRESET_LABELS = [
        '15 minutes',
        '30 minutes',
        '45 minutes',
        '60 minutes',
        '90 minutes',
        '120 minutes',
    ];

    /**
     * Index of the "Cancel" option (last in the list).
     */
    private const CANCEL_INDEX = 6;

    /**
     * @param int $cursor   index into PRESET_LABELS (0-5) or CANCEL_INDEX (6)
     * @param int $winWidth window width in cells
     * @param int $winHeight window height in rows
     */
    private function __construct(
        private int $cursor,
        private int $winWidth,
        private int $winHeight,
    ) {
    }

    /**
     * Open the menu with the given cursor position.
     */
    public static function open(int $initialCursor, int $cols, int $rows): self
    {
        [$w, $h] = self::dims($cols, $rows);

        return new self($initialCursor, $w, $h);
    }

    public function up(): self
    {
        $next = clone $this;
        $next->cursor = max(0, $this->cursor - 1);

        return $next;
    }

    public function down(): self
    {
        $next = clone $this;
        $next->cursor = min(self::CANCEL_INDEX, $this->cursor + 1);

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

    /**
     * The preset index (0-5) for the cursor, or -1 if on "Cancel".
     *
     * @return int
     */
    public function selectedPresetIndex(): int
    {
        if ($this->cursor === self::CANCEL_INDEX) {
            return -1;
        }

        return $this->cursor;
    }

    /**
     * True when the cursor is on the "Cancel" option.
     */
    public function isCancel(): bool
    {
        return $this->cursor === self::CANCEL_INDEX;
    }

    /** Composite the menu box centred over a sugar-veil dimmed background. */
    public function render(string $background): string
    {
        $box = SugarBoxer::new()->render(
            SugarBoxer::new()->leaf($this->body())->withBorder(true)->withPadding(0)->withTitle(' Sleep Timer '),
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
        foreach (self::PRESET_LABELS as $i => $label) {
            $lines[] = $i === $this->cursor
                ? Style::new()->reverse()->bold()->render('▶ ' . $label)
                : '  ' . $label;
        }
        // Cancel option
        $cancelLabel = 'Cancel';
        $lines[] = $this->cursor === self::CANCEL_INDEX
            ? Style::new()->reverse()->bold()->render('▶ ' . $cancelLabel)
            : '  ' . $cancelLabel;

        return implode("\n", $lines);
    }

    /**
     * @return array{int, int} [winWidth, winHeight]
     */
    private static function dims(int $cols, int $rows): array
    {
        $w = max(self::MIN_WIDTH, min($cols - 8, self::MAX_WIDTH));
        $h = max(3, min(max(3, $rows - 4), self::CANCEL_INDEX + 2));

        return [$w, $h];
    }

    // ---- accessors (for tests) ----------------------------------------

    public function cursor(): int
    {
        return $this->cursor;
    }
}

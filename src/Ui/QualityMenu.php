<?php

declare(strict_types=1);

namespace Phlix\Console\Ui;

use Phlix\Console\Api\Dto\Rendition;
use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;

/**
 * The in-player quality picker overlay. A small single-column menu — "Auto"
 * (server-driven ABR, the master stream) followed by each ABR-ladder
 * {@see Rendition} the current transcode exposes (highest-first) — composited as
 * a bordered box centred over a sugar-veil dimmed backdrop, mirroring the
 * {@see CommandPalette} overlay pattern.
 *
 * The two upstream primitives that matter — sugar-veil's `composite()` (dim +
 * centre) and sugar-boxer's bordered box — are reused verbatim; only the trivial
 * cursor and the Rendition→row mapping (the phlix-specific glue) live here.
 *
 * Immutable (clone-mutate). The cursor never leaves `[0, count(options)-1]`;
 * option 0 is always "Auto" so the menu is meaningful even when the ladder is
 * empty (a legacy / unscanned item → "Auto" is the only row).
 */
final class QualityMenu
{
    private const MAX_WIDTH = 40;
    private const MIN_WIDTH = 22;
    private const BACKDROP_DIM = 40;

    /**
     * @param list<Rendition> $variants the pickable rungs (highest-first), excluding "Auto"
     */
    private function __construct(
        private array $variants,
        private int $cursor,
        private int $winWidth,
        private int $winHeight,
    ) {
    }

    /**
     * Open the menu over the given terminal size, pre-highlighting the currently
     * pinned rendition (`$selectedId` null → "Auto"). An unknown id falls back to
     * "Auto". Non-playable rungs (no signed `url`) are dropped so every offered
     * row can actually be pinned.
     *
     * @param list<Rendition> $variants
     */
    public static function open(array $variants, ?string $selectedId, int $cols, int $rows): self
    {
        $playable = array_values(array_filter(
            $variants,
            static fn (Rendition $r): bool => $r->url !== null && $r->url !== '',
        ));

        $cursor = 0; // "Auto"
        if ($selectedId !== null) {
            foreach ($playable as $i => $rendition) {
                if ($rendition->id === $selectedId) {
                    $cursor = $i + 1; // +1 for the leading "Auto" row
                    break;
                }
            }
        }

        [$w, $h] = self::dims($cols, $rows, count($playable) + 1);

        return new self($playable, $cursor, $w, $h);
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
        $next->cursor = min($this->rowCount() - 1, $this->cursor + 1);

        return $next;
    }

    public function resizedTo(int $cols, int $rows): self
    {
        [$w, $h] = self::dims($cols, $rows, $this->rowCount());

        $next = clone $this;
        $next->winWidth = $w;
        $next->winHeight = $h;

        return $next;
    }

    /** True when the cursor is on the "Auto" row (server ABR — no pin). */
    public function isAuto(): bool
    {
        return $this->cursor === 0;
    }

    /** The rendition under the cursor, or null on the "Auto" row. */
    public function selectedRendition(): ?Rendition
    {
        if ($this->cursor === 0) {
            return null;
        }

        return $this->variants[$this->cursor - 1] ?? null;
    }

    /** Composite the menu box centred over a sugar-veil dimmed background. */
    public function render(string $background): string
    {
        $box = SugarBoxer::new()->render(
            SugarBoxer::new()->leaf($this->body())->withBorder(true)->withPadding(0)->withTitle(' Quality '),
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
        foreach ($this->rowLabels() as $i => $label) {
            $lines[] = $i === $this->cursor
                ? Style::new()->reverse()->bold()->render('▶ ' . $label)
                : '  ' . $label;
        }

        return implode("\n", $lines);
    }

    /** @return list<string> the visible rows, "Auto" first then each rung. */
    private function rowLabels(): array
    {
        $labels = ['Auto (recommended)'];
        foreach ($this->variants as $rendition) {
            $labels[] = $rendition->displayLabel();
        }

        return $labels;
    }

    private function rowCount(): int
    {
        return count($this->variants) + 1;
    }

    /**
     * @return array{int, int} [winWidth, winHeight]
     */
    private static function dims(int $cols, int $rows, int $rowCount): array
    {
        $w = max(self::MIN_WIDTH, min($cols - 8, self::MAX_WIDTH));
        // Rows + top/bottom border (2). Never taller than the terminal leaves room for.
        $h = max(3, min(max(3, $rows - 4), $rowCount + 2));

        return [$w, $h];
    }

    // ---- accessors (for tests) ----------------------------------------

    public function cursor(): int
    {
        return $this->cursor;
    }

    /** @return list<string> the option rows, in order ("Auto" first). */
    public function optionLabels(): array
    {
        return $this->rowLabels();
    }
}

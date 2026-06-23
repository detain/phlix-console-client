<?php

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Hermit\FilteredItem;
use SugarCraft\Hermit\Hermit;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;

/**
 * The ⌘K-style command palette overlay. Wraps a candy-hermit {@see Hermit}
 * (configured with a candy-fuzzy ranker so typing fuzzy-ranks the actions) over
 * an action registry, and composites the list box centered above a sugar-veil
 * dimmed backdrop.
 *
 * Immutable (clone-mutate). The box renders the fuzzy-matched characters of each
 * action in bold and pops as a bright modal over a dimmed backdrop: candy-hermit's
 * View() and sugar-veil's composite() are both ANSI-width-aware, so the styled
 * (highlighted) item lines survive the width math and compositing intact.
 *
 * @phpstan-type Actions list<PaletteAction>
 */
final class CommandPalette
{
    private const MAX_WIDTH = 50;
    private const MIN_WIDTH = 24;
    private const MAX_VISIBLE = 10;
    private const BACKDROP_DIM = 40;

    /**
     * @param list<PaletteAction> $actions
     */
    private function __construct(
        private Hermit $hermit,
        private array $actions,
        private int $cols,
        private int $rows,
        private int $winWidth,
        private int $winHeight,
    ) {
    }

    /**
     * @param list<PaletteAction> $actions
     */
    public static function open(array $actions, int $cols, int $rows): self
    {
        [$w, $h] = self::dims($cols, $rows, count($actions));
        $hermit = self::buildHermit($actions, $w, $h);

        return new self($hermit, $actions, $cols, $rows, $w, $h);
    }

    /**
     * Replace the action registry, preserving the typed query (used to augment
     * the palette with library actions that arrive asynchronously after open).
     *
     * @param list<PaletteAction> $actions
     */
    public function withActions(array $actions): self
    {
        $next = clone $this;
        $next->actions = $actions;
        // withItems() re-applies the current filter text, so a query typed before
        // the libraries arrived is kept.
        $next->hermit = $this->hermit->withItems(self::items($actions));

        return $next;
    }

    public function type(string $rune): self
    {
        $next = clone $this;
        $next->hermit = $this->hermit->type($rune);

        return $next;
    }

    public function backspace(): self
    {
        $next = clone $this;
        $next->hermit = $this->hermit->backspace();

        return $next;
    }

    public function up(): self
    {
        $next = clone $this;
        $next->hermit = $this->hermit->cursorUp();

        return $next;
    }

    public function down(): self
    {
        $next = clone $this;
        $next->hermit = $this->hermit->cursorDown();

        return $next;
    }

    public function resizedTo(int $cols, int $rows): self
    {
        [$w, $h] = self::dims($cols, $rows, count($this->actions));

        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;
        $next->winWidth = $w;
        $next->winHeight = $h;
        // setWindowWidth/Height return clones that keep the filter/cursor/items.
        $next->hermit = $this->hermit->setWindowWidth($w)->setWindowHeight($h);

        return $next;
    }

    /** The action under the cursor (mapped back via the item's 1-based ordinal). */
    public function selectedAction(): ?PaletteAction
    {
        $item = $this->hermit->selected();
        if ($item === null) {
            return null;
        }

        return $this->actions[$item->number() - 1] ?? null;
    }

    /** Composite the palette box centered over a sugar-veil dimmed background. */
    public function render(string $background): string
    {
        $blank = implode("\n", array_fill(0, $this->winHeight, str_repeat(' ', $this->winWidth)));
        $box = $this->hermit->View($blank);

        return Veil::new()
            ->withBackdrop(self::BACKDROP_DIM)
            ->composite($box, $background, Position::CENTER, Position::CENTER);
    }

    // ---- helpers -------------------------------------------------------

    /**
     * @param list<PaletteAction> $actions
     * @return array{int, int} [winWidth, winHeight]
     */
    private static function dims(int $cols, int $rows, int $actionCount): array
    {
        $w = max(self::MIN_WIDTH, min($cols - 8, self::MAX_WIDTH));
        $visible = max(1, min($actionCount, self::MAX_VISIBLE));
        // Hermit shows windowHeight-2 items (header + separator take two rows).
        $h = max(3, min($rows - 4, $visible + 2));

        return [$w, $h];
    }

    /**
     * @param list<PaletteAction> $actions
     */
    private static function buildHermit(array $actions, int $winWidth, int $winHeight): Hermit
    {
        return Hermit::new(self::items($actions))
            ->setRanker(new SmithWatermanMatcher())
            ->setPrompt('› ')
            ->setMatchStyle("\e[1m") // bold the fuzzy-matched runes (ANSI-safe now)
            ->setItemFormatter(static fn (string $value, bool $selected): string => ($selected ? '▶ ' : '  ') . $value)
            ->setWindowWidth($winWidth)
            ->setWindowHeight($winHeight)
            ->setOffset(0, 0); // marks the hermit shown; sugar-veil re-centers it
    }

    /**
     * @param list<PaletteAction> $actions
     * @return list<FilteredItem>
     */
    private static function items(array $actions): array
    {
        $items = [];
        foreach ($actions as $i => $action) {
            $items[] = new FilteredItem($i + 1, $action->label);
        }

        return $items;
    }

    // ---- accessors (for tests) ----------------------------------------

    /** @return list<PaletteAction> */
    public function actions(): array
    {
        return $this->actions;
    }

    public function filterText(): string
    {
        return $this->hermit->filterText();
    }

    /** The currently visible (filtered + ranked) action labels, in order. */
    public function visibleLabels(): array
    {
        return array_map(static fn ($item): string => $item->value(), $this->hermit->items());
    }
}

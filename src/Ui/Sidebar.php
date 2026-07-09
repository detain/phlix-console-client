<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;

/**
 * The libraries left-nav: a fixed-width vertical list of library entries with a
 * single selected row, windowed to a given height so a long library list scrolls
 * around the selection. Immutable — the owning screen sets the entries (when
 * libraries load), moves the selection with {@see up()}/{@see down()}, reads
 * {@see selectedId()} on Enter, and toggles the focus styling with
 * {@see withFocus()}.
 *
 * When focused the selected row is reverse-highlighted; when the rails region has
 * focus instead the selection is shown bold so it stays legible in the
 * background. The widget decodes no keys of its own — the screen wires them.
 */
final readonly class Sidebar
{
    private const TITLE = 'Libraries';
    private const DEFAULT_WIDTH = 22;

    /** @param list<array{id: string, label: string}> $entries */
    public function __construct(
        public array $entries = [],
        public int $cursor = 0,
        public int $width = self::DEFAULT_WIDTH,
        public bool $focused = false,
    ) {
    }

    public static function new(int $width = self::DEFAULT_WIDTH): self
    {
        return new self(width: max(4, $width));
    }

    /**
     * Replace the entries (e.g. when libraries (re)load), clamping the selection
     * into the new range.
     *
     * @param list<array{id: string, label: string}> $entries
     */
    public function withEntries(array $entries): self
    {
        $cursor = $entries === [] ? 0 : min($this->cursor, count($entries) - 1);

        return new self($entries, $cursor, $this->width, $this->focused);
    }

    public function withFocus(bool $focused): self
    {
        return $focused === $this->focused
            ? $this
            : new self($this->entries, $this->cursor, $this->width, $focused);
    }

    public function up(): self
    {
        return $this->cursor === 0
            ? $this
            : new self($this->entries, $this->cursor - 1, $this->width, $this->focused);
    }

    public function down(): self
    {
        $last = count($this->entries) - 1;

        return $this->cursor >= $last
            ? $this
            : new self($this->entries, $this->cursor + 1, $this->width, $this->focused);
    }

    /** The id of the selected entry, or null when the list is empty. */
    public function selectedId(): ?string
    {
        return $this->entries[$this->cursor]['id'] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Render a {@see width} × $height block: a bold title row, then one padded
     * row per visible entry (windowed around the selection), blank-filled to
     * height so it composes cleanly beside the rails via a horizontal join.
     */
    public function render(int $height): string
    {
        $height = max(1, $height);
        $blank = str_repeat(' ', $this->width);

        $rows = [Style::new()->bold()->render($this->cell(self::TITLE))];

        $listHeight = max(1, $height - 1);
        foreach ($this->window($listHeight) as $i) {
            $label = $this->cell(' ' . $this->entries[$i]['label']);
            if ($i !== $this->cursor) {
                $rows[] = $label;
            } elseif ($this->focused) {
                $rows[] = Style::new()->reverse()->bold()->render($label);
            } else {
                $rows[] = Style::new()->bold()->render($label);
            }
        }

        while (count($rows) < $height) {
            $rows[] = $blank;
        }

        return implode("\n", array_slice($rows, 0, $height));
    }

    /** Truncate then pad a label to exactly {@see width} display columns. */
    private function cell(string $label): string
    {
        return Width::padRight(Width::truncate($label, $this->width), $this->width);
    }

    /**
     * The entry indices to show for a $listHeight-row viewport, scrolled so the
     * selected entry stays roughly centred and on screen.
     *
     * @return list<int>
     */
    private function window(int $listHeight): array
    {
        $count = count($this->entries);
        if ($count === 0) {
            return [];
        }
        $start = 0;
        if ($count > $listHeight) {
            $start = max(0, min($this->cursor - intdiv($listHeight - 1, 2), $count - $listHeight));
        }
        $end = min($count, $start + $listHeight);

        return range($start, $end - 1);
    }
}

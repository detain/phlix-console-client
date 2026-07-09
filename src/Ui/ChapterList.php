<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use Phlix\Console\Api\Dto\Chapter;
use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;

/**
 * The in-player chapter picker overlay. A small single-column menu listing
 * all available chapters — composited as a bordered box centred over a
 * sugar-veil dimmed backdrop, mirroring the {@see AudioTrackList} overlay pattern.
 *
 * Immutable (clone-mutate). The cursor never leaves [0, count(chapters)-1].
 */
final class ChapterList
{
    private const MAX_WIDTH = 56;
    private const MIN_WIDTH = 28;
    private const BACKDROP_DIM = 40;

    /**
     * @param list<Chapter> $chapters the pickable chapters
     */
    private function __construct(
        private array $chapters,
        private int $cursor,
        private int $winWidth,
        private int $winHeight,
    ) {
    }

    /**
     * Open the menu over the given terminal size, pre-highlighting the chapter
     * containing $currentSeconds (or the first chapter if none). An unknown
     * position falls back to the first chapter.
     *
     * @param list<Chapter> $chapters
     */
    public static function open(array $chapters, float $currentSeconds, int $cols, int $rows): self
    {
        $cursor = 0;
        foreach ($chapters as $i => $chapter) {
            if ($chapter->start <= $currentSeconds && $currentSeconds < $chapter->end) {
                $cursor = $i;
                break;
            }
        }

        [$w, $h] = self::dims($cols, $rows, count($chapters));

        return new self($chapters, $cursor, $w, $h);
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

    /** The chapter under the cursor. */
    public function selectedChapter(): ?Chapter
    {
        return $this->chapters[$this->cursor] ?? null;
    }

    /** Composite the menu box centred over a sugar-veil dimmed background. */
    public function render(string $background): string
    {
        $box = SugarBoxer::new()->render(
            SugarBoxer::new()->leaf($this->body())->withBorder(true)->withPadding(0)->withTitle(' Chapters '),
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

    /** @return list<string> the visible rows. */
    private function rowLabels(): array
    {
        $cursor = $this->cursor;

        return array_map(
            fn (Chapter $chapter, int $idx) => sprintf(
                '%s  %s',
                $this->formatTime($chapter->start),
                $chapter->title ?: 'Chapter ' . ($idx + 1),
            ),
            $this->chapters,
            array_keys($this->chapters),
        );
    }

    private function rowCount(): int
    {
        return count($this->chapters);
    }

    private function formatTime(float $seconds): string
    {
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        $s = (int) floor($seconds % 60);
        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }

        return sprintf('%d:%02d', $m, $s);
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

    /** @return list<Chapter> the chapters, in order. */
    public function chapters(): array
    {
        return $this->chapters;
    }
}
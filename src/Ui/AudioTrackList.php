<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use Phlix\Console\Api\Dto\StreamAudioTrack;
use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;

/**
 * The in-player audio track picker overlay. A small single-column menu listing
 * all available audio tracks — composited as a bordered box centred over a
 * sugar-veil dimmed backdrop, mirroring the {@see QualityMenu} overlay pattern.
 *
 * Immutable (clone-mutate). The cursor never leaves [0, count(tracks)-1].
 */
final class AudioTrackList
{
    private const MAX_WIDTH = 48;
    private const MIN_WIDTH = 24;
    private const BACKDROP_DIM = 40;

    /**
     * @param list<StreamAudioTrack> $tracks the pickable audio tracks
     */
    private function __construct(
        private array $tracks,
        private int $cursor,
        private int $winWidth,
        private int $winHeight,
    ) {
    }

    /**
     * Open the menu over the given terminal size, pre-highlighting the currently
     * selected track (`$selectedId` null → first track). An unknown id falls back
     * to the first track.
     *
     * @param list<StreamAudioTrack> $tracks
     */
    public static function open(array $tracks, ?string $selectedId, int $cols, int $rows): self
    {
        $cursor = 0;
        if ($selectedId !== null) {
            foreach ($tracks as $i => $track) {
                if ($track->id === $selectedId) {
                    $cursor = $i;
                    break;
                }
            }
        }

        [$w, $h] = self::dims($cols, $rows, count($tracks));

        return new self($tracks, $cursor, $w, $h);
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

    /** The audio track under the cursor. */
    public function selectedTrack(): ?StreamAudioTrack
    {
        return $this->tracks[$this->cursor] ?? null;
    }

    /** The id of the selected track, or null if list is empty. */
    public function selectedId(): ?string
    {
        return $this->selectedTrack()?->id;
    }

    /** Composite the menu box centred over a sugar-veil dimmed background. */
    public function render(string $background): string
    {
        $box = SugarBoxer::new()->render(
            SugarBoxer::new()->leaf($this->body())->withBorder(true)->withPadding(0)->withTitle(' Audio Tracks '),
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
        return array_map(
            static fn (StreamAudioTrack $track): string => $track->displayLabel(),
            $this->tracks,
        );
    }

    private function rowCount(): int
    {
        return count($this->tracks);
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

    /** @return list<StreamAudioTrack> the tracks, in order. */
    public function tracks(): array
    {
        return $this->tracks;
    }
}

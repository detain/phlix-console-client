<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Graft;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;

/**
 * Unit law of the absolute-move graft: box rows ride `Ansi::cursorTo()`
 * suffixes so a frame's own line structure — a graphics-mode player's single
 * opaque blob line, see {@see \Phlix\Console\Screen\LineAligned} — is never
 * byte-split, and the resolved anchors match what sugar-overlay's
 * `Position::CENTER` (`floor((bg − fg) / 2)`) and the SyncPlayOverlay top-right
 * splice (`max(0, cols − boxWidth)`) compute on a plain canvas.
 */
final class GraftTest extends TestCase
{
    /** A miniature sixel payload: DCS…ST on ONE logical line, no "\n" anywhere. */
    private const BLOB = "\x1bPq\"0;1;80;48#0;1;65;65;65;120;120;120;0;0;0#1;2;200;200;200-$!0!1!2\x1b\\";

    /** @return list<string> */
    private function box(int $rows = 3): array
    {
        $lines = [];
        for ($i = 0; $i < $rows; $i++) {
            $lines[] = '│line' . $i . '│';
        }

        return $lines;
    }

    public function testAtAppendsAbsoluteMovesAndLeavesTheFrameHeadIntact(): void
    {
        $frame = self::BLOB . Ansi::cursorTo(24, 1) . Ansi::eraseLine() . 'chrome';

        $out = Graft::at($frame, $this->box(), 2, 5);

        // The frame survives byte-identical as the head of the graft…
        self::assertStringStartsWith($frame, $out);
        // …no newline was introduced (the blob stays the ONLY logical line)…
        self::assertStringNotContainsString("\n", $out);
        // …and each box row rides a 1-based absolute move at (2+i, 5).
        self::assertStringContainsString(Ansi::cursorTo(3, 6) . '│line0│', $out);
        self::assertStringContainsString(Ansi::cursorTo(4, 6) . '│line1│', $out);
        self::assertStringContainsString(Ansi::cursorTo(5, 6) . '│line2│', $out);
    }

    public function testCenteredMirrorsSugarOverlaysCenterAnchor(): void
    {
        // Sugar-overlay CENTER math (Veil::composite): baseY = floor((bg − fg) / 2)
        // over the canvas. For a 5-row box on a 24×80 canvas: floor(19/2)=9, floor(75/2)=37.
        $box = implode("\n", $this->box(5));

        $out = Graft::centered('x', $box, 80, 24);

        self::assertStringContainsString(Ansi::cursorTo(10, 37) . '│line0│', $out);
        self::assertStringContainsString(Ansi::cursorTo(14, 37) . '│line4│', $out);
    }

    public function testCenteredClampsNegativeAnchorsToZero(): void
    {
        // A box TALLER and WIDER than the canvas: Veil clamps the row anchor at 0
        // and column math floors negative → graft must never emit row/col 0.
        $box = implode("\n", ['│abcdefghij│', '│klmnopqrst│']);

        $out = Graft::centered('x', $box, 4, 1);

        self::assertStringContainsString(Ansi::cursorTo(1, 1) . '│abcdefghij│', $out);
    }

    public function testTopRightMirrorsTheOverlayRowSpliceAnchor(): void
    {
        // SyncPlayOverlay::overlayTopRight splices at max(0, cols − boxWidth)
        // starting at row 0. boxWidth here is the measured cell width of the rows.
        $box = ['│ab│', '│cd│']; // Width::string('│ab│') = 4 cells

        $out = Graft::topRight(self::BLOB, $box, 4, 80);

        self::assertStringStartsWith(self::BLOB, $out);
        self::assertStringContainsString(Ansi::cursorTo(1, 77) . '│ab│', $out);
        self::assertStringContainsString(Ansi::cursorTo(2, 77) . '│cd│', $out);
    }

    public function testTheBlobPayloadSurvivesTheGraftByteForByte(): void
    {
        // The corruption the graft exists to prevent: Width::dropAnsi only passes
        // CSI/OSC through, so slicing the blob line severs this DCS payload. The
        // graft must keep it as an untouched head of the frame.
        $out = Graft::centered(self::BLOB, '│box│', 20, 5);

        self::assertStringStartsWith(self::BLOB, $out);
        self::assertSame(1, substr_count($out, "\x1bPq"));
        self::assertSame(substr_count(self::BLOB, '$'), substr_count($out, '$'));
    }

    public function testSplitLinesDropsOnlyTheEmptyTailOfATrailingNewline(): void
    {
        self::assertSame(['a', 'b'], Graft::splitLines("a\nb\n"));
        self::assertSame(['a', '', 'b'], Graft::splitLines("a\n\nb"));
        self::assertSame(['solo'], Graft::splitLines('solo'));
        // A graphics blob splits to exactly ONE line.
        self::assertSame([self::BLOB], Graft::splitLines(self::BLOB));
    }
}

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\SyncPlayModal;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * [review console__tuispec, m41] The create-room form echoed the typed
 * room-name field at full length. A paste longer than the frame pushed the
 * caret past the panel clip — the user types blind, with no cursor visible
 * anywhere on screen. The display head now gets the panel-fit budget
 * (house input-echo law, cf. SearchScreen's query line); the field STATE
 * stays uncapped — the server owns the real room-name limit.
 *
 * Geometry pinned from disk: open() on a 30-col terminal yields
 * winWidth = max(MIN_WIDTH 30, min(30-8, MAX_WIDTH 50)) = 30; the echo
 * budget is winWidth - 12 = 18 cells (border 2 + '  Name: ' 8 + caret 1 +
 * margin 1).
 */
final class SyncPlayModalNameWidthTest extends TestCase
{
    private const LONG_NAME = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @return array{0: string, 1: int} [stripped Name-line, panel box width]
     */
    private function nameLine(SyncPlayModal $modal): array
    {
        $panelWidth = $modal->dims()['width'];

        foreach (explode("\n", Ansi::strip($modal->render(''))) as $line) {
            if (str_contains($line, 'Name: ')) {
                return [$line, $panelWidth];
            }
        }

        $this->fail('the create form rendered no Name: line');
    }

    private function createMode(int $cols = 30): SyncPlayModal
    {
        [$modal, $roomId] = SyncPlayModal::open([], $cols, 24)->select();
        $this->assertNull($roomId, 'cursor 0 enters creation');

        return $modal;
    }

    public function testOverlongTypedNameIsEllipsisedWithTheCaretStillVisible(): void
    {
        [$line, $panelWidth] = $this->nameLine($this->createMode()->appendChar(self::LONG_NAME));

        self::assertStringContainsString('…', $line, 'the echo is truncated to fit');
        self::assertStringContainsString('…█', $line, 'the caret rides the display head, never past the clip');
        self::assertStringContainsString('aaaa', $line, 'the head of the pasted name stays readable');
        self::assertLessThanOrEqual($panelWidth, Width::string($line), 'the row fits the panel box');
    }

    public function testShortNameIsRenderedUntouched(): void
    {
        [$line] = $this->nameLine($this->createMode()->appendChar('Movie Night'));

        self::assertStringContainsString('Movie Night█', $line);
        self::assertStringNotContainsString('…', $line, 'nothing is clipped while it still fits');
    }

    public function testEmptyFieldStillShowsTheBareCaret(): void
    {
        [$line] = $this->nameLine($this->createMode());

        self::assertStringContainsString('Name: █', $line);
    }

    public function testTheBudgetCountsCellsNotCodepoints(): void
    {
        // 8 CJK characters = 16 cells — inside the 18-cell budget; a
        // byte- or codepoint-capped echo would still fit, so the teeth are
        // the second half: 10 more characters take the row over the budget
        // and the ellipsis must land at 18 CELLS, never at 18 codepoints
        // (which would render 36 cells of CJK and re-break the frame).
        [$shortLine, $panelWidth] = $this->nameLine($this->createMode()->appendChar(str_repeat("\u{4F1A}", 8)));
        self::assertStringNotContainsString('…', $shortLine, '16 cells of CJK still fit');

        [$wideLine] = $this->nameLine($this->createMode()->appendChar(str_repeat("\u{4F1A}", 18)));
        self::assertStringContainsString('…', $wideLine);
        self::assertLessThanOrEqual($panelWidth, Width::string($wideLine), 'cell math holds for double-width text');
    }

    public function testTheFieldStateIsNotSilentlyCapped(): void
    {
        // Display law only: the full value must survive to the create call.
        $filled = $this->createMode()->appendChar(self::LONG_NAME);

        self::assertSame(self::LONG_NAME, $filled->roomName(), 'the server, not the frame, owns the limit');
    }
}

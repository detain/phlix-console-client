<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\DisplayCells;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

/**
 * CU-054: the vendored scorer charges ⏸/▶ one cell; emoji-aware terminals
 * charge two. DisplayCells is the conservative metric full-width rows layout
 * against — these pins hold both halves of that law.
 */
final class DisplayCellsTest extends TestCase
{
    public function testPlainTextHasNoReservation(): void
    {
        self::assertSame(0, DisplayCells::extra(''));
        self::assertSame(0, DisplayCells::extra('plain text'));
        self::assertSame(3, DisplayCells::string('abc'));
    }

    public function testTheMediaGlyphsScoreOneExtraCellEach(): void
    {
        self::assertSame(1, DisplayCells::extra('⏸'));
        self::assertSame(1, DisplayCells::extra('▶'));
        self::assertSame(2, DisplayCells::extra('▶⏸'));
        self::assertSame(1, DisplayCells::extra('⏸ x'));
        self::assertSame(4, DisplayCells::string('⏸ x'), 'vendor 3 + the one reserved cell');
    }

    public function testGlyphsTheVendorAlreadyChargesCorrectlyGainNoExtra(): void
    {
        // 👍 is Emoji_Presentation=Yes: Width already scores 2. ↩ (U+21A9) is
        // Emoji_Presentation=No and renders 1. Both must pass through unreserved.
        self::assertSame(0, DisplayCells::extra('👍'));
        self::assertSame(2, DisplayCells::string('👍'));
        self::assertSame(0, DisplayCells::extra('↩'));
        self::assertSame(1, DisplayCells::string('↩'));
    }

    public function testWideEastAsianTextIsNotDoubleCharged(): void
    {
        self::assertSame(0, DisplayCells::extra('你好'));
        self::assertSame(4, DisplayCells::string('你好'));
    }

    public function testSgrIsStrippedButEmbeddedGlyphsStillReserve(): void
    {
        $tinted = "\e[1m⏸ title\e[0m";

        self::assertSame(1, DisplayCells::extra($tinted));
        self::assertSame(8, DisplayCells::string($tinted), 'SGR costs 0, the glyph costs its second cell');
    }

    public function testTruncateMatchesTheVendorByteForByteWithoutGlyphs(): void
    {
        $text = str_repeat('x', 100);

        self::assertSame(Width::truncate($text, 40), DisplayCells::truncate($text, 40));
        self::assertSame(Width::truncate('你好世界', 5), DisplayCells::truncate('你好世界', 5));
    }

    public function testTruncateHoldsALeadingGlyphInsideTheBudget(): void
    {
        $line = '▶' . str_repeat('x', 100);
        $cut = DisplayCells::truncate($line, 40);

        self::assertSame(40, DisplayCells::string($cut), 'the conservative count fills the budget exactly');
        self::assertSame(39, Width::string($cut), 'the under-counted glyph cell is held back');
        self::assertLessThanOrEqual(40, Width::string($cut), 'the vendor metric can never overtype the budget either');
    }

    public function testTruncateAlwaysConvergesInsideTheBudget(): void
    {
        foreach (['⏸⏸xx', 'x⏸y⏸z⏸', '▶', ''] as $text) {
            foreach ([0, 1, 2, 5, 9] as $cells) {
                self::assertLessThanOrEqual(
                    $cells,
                    DisplayCells::string(DisplayCells::truncate($text, $cells)),
                    "<{$text}> must fit {$cells} cells",
                );
            }
        }
    }

    public function testNonPositiveBudgetYieldsNothing(): void
    {
        self::assertSame('', DisplayCells::truncate('anything', 0));
        self::assertSame('', DisplayCells::truncate('anything', -5));
    }
}

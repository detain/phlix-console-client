<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Ui;

use Phlix\Console\Ui\RecommendationCard;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * M-CONSOLE-68 · 72 — the recommendation card's title line is cell-aware
 * end to end. The shipped form before the cure byte-padded a server title
 * into a fixed 50-char str_pad block with NO truncation at all: any title
 * longer than the budget ran past it verbatim and wrapped on narrow
 * terminals (CJK titles wrap at half their true cell cost). The cure takes
 * the caller's width and routes the cut through the shared {@see \Phlix\Console\Ui\Truncate}
 * choke plus {@see Width::padRight()}, so line one is always exactly the
 * gutter plus the budget cells.
 */
final class RecommendationCardWidthTest extends TestCase
{
    private const LONG_TITLE = 'The Extremely Long Title That Will Never Fit Any Terminal';

    public function testALongAsciiTitleIsCutToTheBudgetAndPaddedBackToIt(): void
    {
        $line1 = self::firstLine(self::card(self::LONG_TITLE)->render(Theme::nocturne(), 20));

        self::assertSame(22, Width::string(Ansi::strip($line1)), 'two-cell star gutter plus the twenty-cell title budget');
        self::assertStringContainsString('…', Ansi::strip($line1));
        self::assertStringNotContainsString('Terminal', Ansi::strip($line1), 'the overflow text must be gone, not wrapped');
    }

    public function testALongCjkTitleCostsCellsAndNeverSplitsACodepoint(): void
    {
        $line1 = self::firstLine(self::card('日本語のとても長いタイトルです')->render(Theme::nocturne(), 10));
        $plain = Ansi::strip($line1);

        self::assertSame(12, Width::string($plain), 'two-cell gutter plus the ten-cell budget, cells not codepoints');
        self::assertTrue(mb_check_encoding($plain, 'UTF-8'), 'the cut must land on cluster boundaries');
        self::assertStringNotContainsString("\u{FFFD}", $plain);
    }

    public function testTheDefaultWidthStillPadsToTheShippedFiftyCellBlock(): void
    {
        $card = self::card(self::LONG_TITLE);

        $line1 = self::firstLine($card->render(Theme::nocturne()));

        self::assertSame(52, Width::string(Ansi::strip($line1)), 'the default budget is the historical 50-cell block');
        self::assertStringContainsString('…', Ansi::strip($line1));
    }

    private static function card(string $title): RecommendationCard
    {
        return RecommendationCard::fromArray(['id' => 'x', 'title' => $title, 'year' => 2024, 'score' => 0.5]);
    }

    private static function firstLine(string $rendered): string
    {
        return explode("\n", $rendered)[0];
    }
}

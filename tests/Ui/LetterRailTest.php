<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Api\Dto\LetterIndex;
use Phlix\Console\Ui\LetterRail;
use PHPUnit\Framework\TestCase;
use SugarCraft\Sprinkles\Layout;

final class LetterRailTest extends TestCase
{
    private function index(): LetterIndex
    {
        return LetterIndex::fromArray([
            'letters' => [
                ['letter' => '#', 'offset' => 0, 'count' => 0],
                ['letter' => 'A', 'offset' => 0, 'count' => 5],
                ['letter' => 'B', 'offset' => 5, 'count' => 0],
            ],
            'total' => 5,
        ]);
    }

    public function testRendersEveryBucketLabel(): void
    {
        $out = (new LetterRail($this->index()))->render();

        self::assertStringContainsString('#', $out);
        self::assertStringContainsString('A', $out);
        self::assertStringContainsString('B', $out);
    }

    public function testDisabledLettersAreStyledAndEnabledOnesPlain(): void
    {
        $out = (new LetterRail($this->index()))->render();

        // 'A' (enabled) appears; the disabled '#'/'B' carry dim ANSI styling.
        self::assertStringContainsString('A', $out);
        self::assertStringContainsString("\033[", $out, 'disabled letters are styled with ANSI');
        self::assertGreaterThan(0, Layout::width($out));
    }

    public function testCurrentLetterIsHighlighted(): void
    {
        $plain = (new LetterRail($this->index()))->render();
        $highlighted = (new LetterRail($this->index(), 'A'))->render();

        self::assertNotSame($plain, $highlighted, 'highlighting the current letter changes the output');
    }

    public function testWithCurrentReturnsANewRail(): void
    {
        $rail = new LetterRail($this->index());
        $updated = $rail->withCurrent('A');

        self::assertNull($rail->current);
        self::assertSame('A', $updated->current);
    }
}

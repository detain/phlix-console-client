<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Widget;

use Phlix\Console\Widget\PosterCard;
use Phlix\Console\Widget\Rail;
use PHPUnit\Framework\TestCase;

final class RailTest extends TestCase
{
    /** @return list<PosterCard> */
    private function cards(int $n): array
    {
        $cards = [];
        for ($i = 0; $i < $n; $i++) {
            $cards[] = new PosterCard("id-{$i}", "Card {$i}");
        }

        return $cards;
    }

    public function testRenderShowsTitleAndVisibleCards(): void
    {
        $rail = new Rail('Movies', $this->cards(3));

        $render = $rail->render(railWidth: 80, focused: true, cardWidth: 12, posterHeight: 4);

        self::assertStringContainsString('Movies', $render);
        self::assertStringContainsString('Card 0', $render);
        self::assertStringContainsString('(1/3)', $render);
        self::assertStringContainsString('●', $render, 'focused rail marker');
    }

    public function testUnfocusedRailMarker(): void
    {
        $render = (new Rail('TV', $this->cards(1)))->render(80, false, 12, 4);

        self::assertStringContainsString('○', $render);
    }

    public function testEmptyRailRendersPlaceholder(): void
    {
        $render = (new Rail('Empty'))->render(80, true, 12, 4);

        self::assertStringContainsString('Empty', $render);
        self::assertStringContainsString('(no items)', $render);
    }

    public function testMoveCursorClampsAtBothEnds(): void
    {
        $rail = new Rail('R', $this->cards(4));

        self::assertSame(3, $rail->moveCursor(+10, 6)->cursor);
        self::assertSame(0, $rail->moveCursor(-10, 6)->cursor);
    }

    public function testMoveCursorScrollsToKeepCursorVisible(): void
    {
        $rail = new Rail('R', $this->cards(10)); // perRow 3

        $moved = $rail
            ->moveCursor(1, 3)
            ->moveCursor(1, 3)
            ->moveCursor(1, 3); // cursor 3 → beyond first window [0..2]

        self::assertSame(3, $moved->cursor);
        self::assertSame(1, $moved->scroll, 'scrolled so the cursor stays visible');
    }

    public function testMoveCursorOnEmptyIsNoOp(): void
    {
        $rail = new Rail('R');

        self::assertSame($rail, $rail->moveCursor(1, 3));
    }

    public function testWithCardsClampsCursor(): void
    {
        $rail = (new Rail('R', $this->cards(5)))->moveCursor(4, 6); // cursor 4

        $shrunk = $rail->withCards($this->cards(2));

        self::assertSame(1, $shrunk->cursor);
    }

    public function testWithCardReplacesMatchingId(): void
    {
        $rail = new Rail('R', $this->cards(3));

        $updated = $rail->withCard((new PosterCard('id-1', 'Card 1'))->withPoster('POSTER'));

        self::assertTrue($updated->cards[1]->hasPoster());
        self::assertFalse($updated->cards[0]->hasPoster());
    }

    public function testFocusedCard(): void
    {
        $rail = (new Rail('R', $this->cards(3)))->moveCursor(2, 6);

        self::assertSame('id-2', $rail->focusedCard()?->id);
    }

    public function testIsEmptyAndWithCardsToEmpty(): void
    {
        self::assertTrue((new Rail('R'))->isEmpty());

        $rail = (new Rail('R', $this->cards(3)))->moveCursor(2, 6);
        self::assertFalse($rail->isEmpty());

        $emptied = $rail->withCards([]);
        self::assertTrue($emptied->isEmpty());
        self::assertSame(0, $emptied->cursor);
        self::assertNull($emptied->focusedCard());
    }

    public function testPerRowMath(): void
    {
        // 80 wide, 12-cell cards, 2 gap → floor((80+2)/(12+2)) = 5
        self::assertSame(5, Rail::perRow(80, 12, 2));
        self::assertGreaterThanOrEqual(1, Rail::perRow(4, 100, 2));
    }
}

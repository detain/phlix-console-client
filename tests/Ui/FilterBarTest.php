<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\FilterBar;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

final class FilterBarTest extends TestCase
{
    private function char(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune);
    }

    public function testNewIsEmptyAndSearchFocused(): void
    {
        $bar = FilterBar::new();

        self::assertSame('', $bar->search);
        self::assertNull($bar->sort);
        self::assertNull($bar->order);
        self::assertSame(0, $bar->active);
        self::assertFalse($bar->isActive());
    }

    public function testTypingAppendsToSearch(): void
    {
        $bar = FilterBar::new()->handleKey($this->char('m'))->handleKey($this->char('a'));

        self::assertSame('ma', $bar->search);
        self::assertTrue($bar->isActive());
    }

    public function testSpaceAndBackspaceEditSearch(): void
    {
        $bar = FilterBar::new()
            ->handleKey($this->char('a'))
            ->handleKey(new KeyMsg(KeyType::Space))
            ->handleKey($this->char('b'));
        self::assertSame('a b', $bar->search);

        $bar = $bar->handleKey(new KeyMsg(KeyType::Backspace));
        self::assertSame('a ', $bar->search);

        // Backspace on empty is a no-op (same instance).
        $empty = FilterBar::new();
        self::assertSame($empty, $empty->handleKey(new KeyMsg(KeyType::Backspace)));
    }

    public function testNextAndPrevCycleControls(): void
    {
        $bar = FilterBar::new();

        self::assertSame(1, $bar->next()->active);
        self::assertSame(2, $bar->next()->next()->active);
        self::assertSame(0, $bar->next()->next()->next()->active, 'wraps back to search');
        self::assertSame(2, $bar->prev()->active, 'prev from search wraps to order');
    }

    public function testSortControlCyclesFields(): void
    {
        $bar = FilterBar::new()->next(); // focus Sort

        $right = $bar->handleKey(new KeyMsg(KeyType::Right));
        self::assertSame('year', $right->sort, 'name → year');

        $left = $bar->handleKey(new KeyMsg(KeyType::Left));
        self::assertSame('runtime', $left->sort, 'name → (wrap) runtime');
    }

    public function testOrderControlToggles(): void
    {
        $bar = FilterBar::new()->next()->next(); // focus Order

        $desc = $bar->handleKey(new KeyMsg(KeyType::Right));
        self::assertSame('desc', $desc->order);
        self::assertSame('asc', $desc->handleKey(new KeyMsg(KeyType::Space))->order);
    }

    public function testControlsIgnoreIrrelevantKeys(): void
    {
        // Sort control ignores a typed letter; search control ignores arrows —
        // each returns the same instance (a no-op).
        $sortBar = FilterBar::new()->next();
        self::assertSame($sortBar, $sortBar->handleKey($this->char('x')));

        $searchBar = FilterBar::new();
        self::assertSame($searchBar, $searchBar->handleKey(new KeyMsg(KeyType::Right)));
    }

    public function testRenderHighlightsTheActiveControl(): void
    {
        $searchFocused = FilterBar::new()->render();
        $sortFocused = FilterBar::new()->next()->render();

        self::assertStringContainsString('Search:', $searchFocused);
        self::assertStringContainsString('Sort: name', $searchFocused);
        self::assertStringContainsString('Order: asc', $searchFocused);
        self::assertStringContainsString("\033[", $searchFocused, 'the active control is styled');
        self::assertNotSame($searchFocused, $sortFocused, 'moving focus changes the highlight');
    }
}

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
        self::assertSame(0, $bar->next()->next()->next()->active, 'wraps back to search without facets (3-cycle)');
        self::assertSame(2, $bar->prev()->active, 'prev from search wraps to order');
    }

    public function testGenreJoinsTheCycleOnlyWithFacets(): void
    {
        $bar = FilterBar::new()->withGenres(['Drama', 'Comedy']);

        $order = $bar->next()->next();
        self::assertSame(2, $order->active);

        $genre = $order->next();
        self::assertSame(3, $genre->active, 'with facets the cycle reaches a 4th control');
        self::assertSame(0, $genre->next()->active, 'and wraps back to search');
        $prev = FilterBar::new()->withGenres(['Drama'])->prev();
        self::assertSame(3, $prev->active, 'with facets, prev from search wraps to genre');

        // Facet-less bars keep the original 3-control cycle.
        self::assertSame(0, $bar->withGenres([])->next()->next()->next()->active);
    }

    public function testGenreArrowsMoveCursorWithWrap(): void
    {
        $bar = FilterBar::new()->withGenres(['Drama', 'Comedy', 'Sci-Fi'])->next()->next()->next();
        self::assertSame(3, $bar->active, 'focused GENRE');
        self::assertSame(0, $bar->genreCursorIndex());

        self::assertSame(1, $bar->handleKey(new KeyMsg(KeyType::Right))->genreCursorIndex());
        $doubleRight = $bar->handleKey(new KeyMsg(KeyType::Right))->handleKey(new KeyMsg(KeyType::Right));
        self::assertSame(2, $doubleRight->genreCursorIndex());
        $wrapped = $bar->handleKey(new KeyMsg(KeyType::Left));
        self::assertSame(2, $wrapped->genreCursorIndex(), 'left from chip 0 wraps to the last chip');
        self::assertSame(0, $bar->genreCursorIndex(), 'the bar stays immutable');
    }

    public function testGenreSpaceTogglesChipUnderCursor(): void
    {
        $bar = FilterBar::new()->withGenres(['Drama', 'Comedy'])->next()->next()->next();

        $selected = $bar->handleKey(new KeyMsg(KeyType::Space));
        self::assertSame(['Drama'], $selected->genres);
        self::assertSame(0, $selected->genreCursorIndex(), 'toggling keeps the cursor');
        self::assertTrue($selected->isActive());

        $doubled = $selected->handleKey(new KeyMsg(KeyType::Right))->handleKey(new KeyMsg(KeyType::Enter));
        self::assertSame(['Drama', 'Comedy'], $doubled->genres, 'Enter toggles too; selection accumulates');

        $removed = $doubled->handleKey(new KeyMsg(KeyType::Left))->handleKey(new KeyMsg(KeyType::Space));
        self::assertSame(['Comedy'], $removed->genres, 'space on a selected chip deselects it');

        // Unreachable keys are no-ops on the genre control.
        self::assertSame($bar, $bar->handleKey($this->char('x')));
    }

    public function testWithGenresClampsCursorAndFocus(): void
    {
        $focused = FilterBar::new()->withGenres(['A', 'B', 'C'])
            ->next()->next()->next()
            ->handleKey(new KeyMsg(KeyType::Right))->handleKey(new KeyMsg(KeyType::Right));
        self::assertSame(3, $focused->active);
        self::assertSame(2, $focused->genreCursorIndex());

        $shrunk = $focused->withGenres(['A']);
        self::assertSame(0, $shrunk->genreCursorIndex(), 'cursor clamps to the surviving list');

        $emptied = $focused->withGenres([]);
        self::assertSame(0, $emptied->active, 'focus falls back to search when the control disappears');
        self::assertSame(0, $emptied->genreCursorIndex());
    }

    public function testRenderShowsLabeledGenreChips(): void
    {
        $rendered = FilterBar::new()->withGenres(['Drama', 'Comedy'])->render();

        self::assertStringContainsString('Genres:', $rendered);
        self::assertStringContainsString('[Drama]', $rendered);
        self::assertStringContainsString('[Comedy]', $rendered);
        self::assertStringNotContainsString('Genres:', FilterBar::new()->render(), 'no facet section without data');
    }

    public function testSortControlCyclesFields(): void
    {
        $bar = FilterBar::new()->next(); // focus Sort

        $right = $bar->handleKey(new KeyMsg(KeyType::Right));
        self::assertSame('year', $right->sort, 'name → year');

        $left = $bar->handleKey(new KeyMsg(KeyType::Left));
        self::assertSame('artist', $left->sort, 'name → (wrap) artist');
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

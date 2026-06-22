<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Sidebar;
use PHPUnit\Framework\TestCase;
use SugarCraft\Sprinkles\Style;

final class SidebarTest extends TestCase
{
    /** The opening SGR sequence Style emits for a reverse+bold run. */
    private function reverseOpen(): string
    {
        return explode("\x00", Style::new()->reverse()->bold()->render("\x00"))[0];
    }

    /** @param list<string> $names */
    private function entries(string ...$names): array
    {
        $entries = [];
        foreach ($names as $name) {
            $entries[] = ['id' => strtolower($name), 'label' => $name];
        }

        return $entries;
    }

    private function strip(string $s): string
    {
        return preg_replace('/\e\[[0-9;]*m/', '', $s) ?? $s;
    }

    /** @return list<string> */
    private function lines(string $block): array
    {
        return explode("\n", $block);
    }

    public function testNewIsEmpty(): void
    {
        $bar = Sidebar::new();

        self::assertTrue($bar->isEmpty());
        self::assertNull($bar->selectedId());
        self::assertSame(0, $bar->cursor);
    }

    public function testNewClampsAWidthFloor(): void
    {
        self::assertSame(4, Sidebar::new(1)->width);
        self::assertSame(30, Sidebar::new(30)->width);
    }

    public function testWithEntriesSelectsTheFirst(): void
    {
        $bar = Sidebar::new()->withEntries($this->entries('Movies', 'TV', 'Music'));

        self::assertFalse($bar->isEmpty());
        self::assertSame('movies', $bar->selectedId());
    }

    public function testDownAndUpMoveTheSelectionWithClamping(): void
    {
        $bar = Sidebar::new()->withEntries($this->entries('Movies', 'TV', 'Music'));

        $bar = $bar->down();
        self::assertSame('tv', $bar->selectedId());
        $bar = $bar->down();
        self::assertSame('music', $bar->selectedId());

        // Clamp at the bottom.
        $bar = $bar->down();
        self::assertSame('music', $bar->selectedId());

        $bar = $bar->up()->up();
        self::assertSame('movies', $bar->selectedId());

        // Clamp at the top.
        $bar = $bar->up();
        self::assertSame('movies', $bar->selectedId());
    }

    public function testUpDownOnEmptyAreNoOps(): void
    {
        $bar = Sidebar::new();

        self::assertSame(0, $bar->down()->cursor);
        self::assertSame(0, $bar->up()->cursor);
        self::assertNull($bar->down()->selectedId());
    }

    public function testWithEntriesClampsTheCursorWhenFewerEntries(): void
    {
        $bar = Sidebar::new()->withEntries($this->entries('A', 'B', 'C'))->down()->down();
        self::assertSame('c', $bar->selectedId());

        $reloaded = $bar->withEntries($this->entries('A'));
        self::assertSame(0, $reloaded->cursor);
        self::assertSame('a', $reloaded->selectedId());
    }

    public function testWithFocusIsIdempotent(): void
    {
        $bar = Sidebar::new();

        self::assertSame($bar, $bar->withFocus(false));
        self::assertNotSame($bar, $bar->withFocus(true));
        self::assertTrue($bar->withFocus(true)->focused);
    }

    public function testRenderProducesAHeightByWidthBlock(): void
    {
        $bar = Sidebar::new(20)->withEntries($this->entries('Movies', 'TV'));
        $lines = $this->lines($bar->render(8));

        self::assertCount(8, $lines, 'exactly height lines');
        foreach ($lines as $line) {
            self::assertSame(20, mb_strlen($this->strip($line)), 'each line padded to width');
        }
    }

    public function testRenderOfAnEmptySidebarIsJustTitleAndBlanks(): void
    {
        $lines = $this->lines(Sidebar::new(16)->render(5));

        self::assertCount(5, $lines);
        self::assertStringContainsString('Libraries', $this->strip($lines[0]));
        foreach ($lines as $line) {
            self::assertSame(16, mb_strlen($this->strip($line)));
        }
    }

    public function testRenderShowsTitleAndLabels(): void
    {
        $block = $this->strip(Sidebar::new()->withEntries($this->entries('Movies', 'TV'))->render(6));

        self::assertStringContainsString('Libraries', $block);
        self::assertStringContainsString('Movies', $block);
        self::assertStringContainsString('TV', $block);
    }

    public function testFocusedSelectionIsReverseHighlighted(): void
    {
        $entries = $this->entries('Movies', 'TV');
        $focused = Sidebar::new()->withEntries($entries)->withFocus(true)->render(6);
        $unfocused = Sidebar::new()->withEntries($entries)->withFocus(false)->render(6);

        // The reverse-video run marks the focused selection; the unfocused
        // selection is only bold, so the reverse SGR is absent there.
        $reverse = $this->reverseOpen();
        self::assertStringContainsString($reverse, $focused);
        self::assertStringNotContainsString($reverse, $unfocused);
        self::assertNotSame($focused, $unfocused);
    }

    public function testRenderTruncatesLongLabels(): void
    {
        $bar = Sidebar::new(10)->withEntries($this->entries('A Very Long Library Name Indeed'));
        foreach ($this->lines($bar->render(4)) as $line) {
            self::assertSame(10, mb_strlen($this->strip($line)));
        }
    }

    public function testRenderWindowsALongListAroundTheSelection(): void
    {
        // 20 entries, a 6-row block (1 title + 5 list rows). Selecting deep in
        // the list must scroll the window so the selection stays visible.
        $names = [];
        for ($i = 0; $i < 20; $i++) {
            $names[] = 'Lib' . $i;
        }
        $bar = Sidebar::new()->withEntries($this->entries(...$names))->withFocus(true);
        for ($i = 0; $i < 18; $i++) {
            $bar = $bar->down();
        }
        self::assertSame('lib18', $bar->selectedId());

        $block = $this->strip($bar->render(6));
        self::assertStringContainsString('Lib18', $block, 'the selected entry is within the window');
        self::assertStringNotContainsString('Lib0 ', $block, 'the top of the list scrolled off');
    }
}

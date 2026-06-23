<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Msg\GoHomeMsg;
use Phlix\Console\Ui\CommandPalette;
use Phlix\Console\Ui\PaletteAction;
use PHPUnit\Framework\TestCase;

final class CommandPaletteTest extends TestCase
{
    /** @param list<string> $labels */
    private function paletteOf(array $labels, int $cols = 80, int $rows = 24): CommandPalette
    {
        $actions = array_map(static fn (string $l): PaletteAction => new PaletteAction($l, new GoHomeMsg()), $labels);

        return CommandPalette::open($actions, $cols, $rows);
    }

    public function testOpenListsEveryActionUnfiltered(): void
    {
        $p = $this->paletteOf(['Movies', 'Music', 'Books']);

        self::assertSame(['Movies', 'Music', 'Books'], $p->visibleLabels());
        self::assertSame('', $p->filterText());
    }

    public function testTypingFuzzyFiltersAndRanks(): void
    {
        $p = $this->paletteOf(['Movies', 'Music', 'Books']);

        // "Music" is the full subsequence of 'mus' so it ranks first; the matcher
        // is local-alignment, so a weaker partial (e.g. "Movies" via 'm') may
        // also appear lower down — what matters is the ranking.
        $typed = $p->type('m')->type('u')->type('s');

        self::assertSame('mus', $typed->filterText());
        self::assertSame('Music', $typed->visibleLabels()[0], 'the full subsequence ranks first');
    }

    public function testCursorStartsAtTheTopAndMovesDown(): void
    {
        $p = $this->paletteOf(['Apple', 'Banana', 'Cherry']);

        self::assertSame('Apple', $p->selectedAction()?->label);
        self::assertSame('Banana', $p->down()->selectedAction()?->label);
        self::assertSame('Apple', $p->down()->up()->selectedAction()?->label);
    }

    public function testSelectedActionMapsBackAfterReranking(): void
    {
        $p = $this->paletteOf(['Apple', 'Banana', 'Cherry']);

        // 'cher' ranks Cherry to the top; the selected action is still Cherry's.
        $action = $p->type('c')->type('h')->type('e')->type('r')->selectedAction();

        self::assertSame('Cherry', $action?->label);
        self::assertInstanceOf(GoHomeMsg::class, $action?->msg);
    }

    public function testSelectedActionIsNullWhenNothingMatches(): void
    {
        $p = $this->paletteOf(['Movies', 'Books']);

        $typed = $p->type('z')->type('z')->type('z');

        self::assertSame([], $typed->visibleLabels());
        self::assertNull($typed->selectedAction());
    }

    public function testWithActionsPreservesTheTypedQuery(): void
    {
        $p = $this->paletteOf(['Movies', 'Music'])->type('m')->type('u');
        self::assertSame('Music', $p->visibleLabels()[0]);

        $augmented = $p->withActions([
            new PaletteAction('Movies', new GoHomeMsg()),
            new PaletteAction('Music', new GoHomeMsg()),
            new PaletteAction('Musicals', new GoHomeMsg()),
        ]);

        self::assertSame('mu', $augmented->filterText(), 'the query survives the action swap');
        self::assertContains('Musicals', $augmented->visibleLabels());
    }

    public function testBackspaceRemovesTheLastRune(): void
    {
        $p = $this->paletteOf(['Movies', 'Music'])->type('m')->type('u');

        self::assertSame('m', $p->backspace()->filterText());
    }

    public function testResizedToKeepsTheFilterState(): void
    {
        $p = $this->paletteOf(['Movies', 'Music'])->type('mu');

        $resized = $p->resizedTo(120, 40);

        self::assertSame('mu', $resized->filterText());
        self::assertSame('Music', $resized->visibleLabels()[0]);
    }

    public function testRenderCompositesThePlainBoxAndDimsTheBackground(): void
    {
        $p = $this->paletteOf(['Movies', 'Music', 'Books']);
        $bg = implode("\n", array_fill(0, 24, str_repeat('.', 80)));

        $out = $p->render($bg);

        self::assertStringContainsString('Movies', $out, 'the (plain) box content survives compositing');
        self::assertStringContainsString("\e[2m", $out, 'sugar-veil dims the backdrop');
        self::assertSame(24, substr_count($out, "\n") + 1, 'the frame keeps its line count');
    }
}

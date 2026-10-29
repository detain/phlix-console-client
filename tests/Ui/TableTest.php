<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\Table;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * The thin sugar-table adapter: borderless, width-exact, reverse-video selection,
 * and a viewport that keeps the selected row on screen.
 */
final class TableTest extends TestCase
{
    private const COLUMNS = [
        ['title' => 'Name', 'width' => 0],                    // flex
        ['title' => 'Qty', 'width' => 5, 'align' => 'right'],
    ];

    /** @return list<list<string>> $n rows: ["item K", "K"] */
    private static function rows(int $n): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['item ' . $i, (string) $i];
        }

        return $rows;
    }

    private static function lines(string $out): array
    {
        return explode("\n", $out);
    }

    private static function hasReverse(string $line): bool
    {
        return preg_match('/\e\[(?:[0-9;]*;)?7(?:;[0-9;]*)?m/', $line) === 1;
    }

    public function testRendersBorderlessHeaderRuleAndRows(): void
    {
        $out = Table::render(self::COLUMNS, self::rows(3), 0, 30, 10);
        $stripped = Ansi::strip($out);

        self::assertStringNotContainsString('│', $stripped);          // no box border
        self::assertStringContainsString('Name', self::lines($stripped)[0]); // header first
        self::assertSame(str_repeat('─', 30), self::lines($stripped)[1]);     // rule second
        self::assertStringContainsString('item 0', $stripped);
    }

    public function testEveryLineIsExactlyTheRequestedWidth(): void
    {
        foreach ([16, 24, 40] as $w) {
            foreach (self::lines(Table::render(self::COLUMNS, self::rows(4), 1, $w, 10)) as $i => $line) {
                self::assertSame($w, Width::string($line), "line {$i} at width {$w}");
            }
        }
    }

    public function testSelectedRowIsReverseVideoAndOthersAreNot(): void
    {
        $out = self::lines(Table::render(self::COLUMNS, self::rows(3), 1, 30, 10));
        // header(0), rule(1), data 0 (2), data 1 = selected (3), data 2 (4)
        self::assertTrue(self::hasReverse($out[3]), 'selected row reversed');
        self::assertFalse(self::hasReverse($out[2]), 'row above not reversed');
        self::assertFalse(self::hasReverse($out[4]), 'row below not reversed');
    }

    public function testViewportWindowsTheSelectedRowIntoView(): void
    {
        // 50 rows, a 5-row data viewport, selection near the end: the selected
        // row must appear in the rendered window (the scroll follows it).
        $out = Ansi::strip(Table::render(self::COLUMNS, self::rows(50), 47, 30, 5));
        self::assertStringContainsString('item 47', $out, 'the selected row is scrolled into view');
        self::assertStringNotContainsString('item 0', $out, 'far-off rows are not rendered');
    }

    public function testFlexColumnTruncatesRatherThanOverflowing(): void
    {
        $rows = [['a really very long item name that overflows', '1']];
        $out = Ansi::strip(Table::render(self::COLUMNS, $rows, 0, 20, 10));

        foreach (self::lines(Table::render(self::COLUMNS, $rows, 0, 20, 10)) as $line) {
            self::assertSame(20, Width::string($line));
        }
        self::assertStringNotContainsString('overflows', $out);
    }

    public function testEmptyRowsRenderHeaderWithoutError(): void
    {
        $out = Table::render(self::COLUMNS, [], 0, 30, 10);
        self::assertStringContainsString('Name', Ansi::strip($out));
        self::assertFalse(self::hasReverse($out), 'nothing is selected when there are no rows');
    }

    /**
     * M-CONSOLE-46 — the AdminUsersScreen spec: a 68-col fixed budget plus the
     * flex Username, rendered into `cols - 4`. Before the cure sugar-table let
     * the flex column absorb the entire deficit: at 76 content cols Username
     * was a 4-cell stub (`ali…`), at 72 it vanished with its header, at 60 the
     * clip ate even the last fixed header.
     */
    private const ADMIN_COLUMNS = [
        ['title' => 'Username', 'width' => 0],
        ['title' => 'Email', 'width' => 30],
        ['title' => 'Role', 'width' => 8],
        ['title' => 'Status', 'width' => 10],
        ['title' => 'Last login', 'width' => 20],
    ];

    /** @return list<list<string>> */
    private static function adminRows(): array
    {
        return [
            ['alice', 'alice@example.com', 'Admin', 'Active', '2026-10-01'],
            ['bob', 'bob@example.com', 'Member', 'Disabled', '2026-09-15'],
        ];
    }

    public function testPrimarySurvivesTheEightyColumnTerminal(): void
    {
        // 80 terminal cols → 76 content: the crush band the review measured.
        $stripped = Ansi::strip(Table::render(self::ADMIN_COLUMNS, self::adminRows(), 0, 76, 5));

        self::assertStringContainsString('Username', $stripped, 'the header never renders as a stub');
        self::assertStringContainsString('alice', $stripped, 'the primary cell is readable, not `ali…`');
        self::assertStringNotContainsString('Last login', $stripped, 'the rightmost named column yielded first');
        self::assertStringNotContainsString('2026-10-01', $stripped, 'dropped cells are gone, not clipped');
        self::assertStringContainsString('Status', $stripped, 'higher-priority named columns are kept');
    }

    public function testPrimaryIsNeverSilentlyDeleted(): void
    {
        // The deletion band was every content width at or below 72; the sweep
        // pins the law across the whole plausible terminal range: the primary
        // header and its cells survive at every width, each line stays exact.
        for ($w = 8; $w <= 120; $w++) {
            $stripped = Ansi::strip(Table::render(self::ADMIN_COLUMNS, self::adminRows(), 0, $w, 5));

            self::assertStringContainsString('Username', $stripped, "primary header at width {$w}");
            self::assertStringContainsString('alice', $stripped, "primary cell at width {$w}");
            foreach (self::lines($stripped) as $line) {
                self::assertSame($w, Width::string($line), "line width at {$w}");
            }
        }
    }

    public function testNamedColumnsYieldRightToLeftByDeclarationPriority(): void
    {
        // Ladder for 68 fixed + gap cells + the 8-wide 'Username' floor:
        // Last login goes below 80, Status below 59, Role below 48, Email below 39.
        $at79 = Ansi::strip(Table::render(self::ADMIN_COLUMNS, self::adminRows(), 0, 79, 5));
        self::assertStringNotContainsString('Last login', $at79);
        self::assertStringContainsString('Status', $at79);

        $at58 = Ansi::strip(Table::render(self::ADMIN_COLUMNS, self::adminRows(), 0, 58, 5));
        self::assertStringNotContainsString('Status', $at58);
        self::assertStringContainsString('Role', $at58);

        $at47 = Ansi::strip(Table::render(self::ADMIN_COLUMNS, self::adminRows(), 0, 47, 5));
        self::assertStringNotContainsString('Role', $at47);
        self::assertStringContainsString('Email', $at47);

        $at38 = Ansi::strip(Table::render(self::ADMIN_COLUMNS, self::adminRows(), 0, 38, 5));
        self::assertStringNotContainsString('Email', $at38);
        self::assertStringContainsString('Username', $at38);
        self::assertStringContainsString('alice', $at38);
    }

    public function testDegenerateWidthsShrinkThePrimaryInsteadOfDeletingIt(): void
    {
        // Below the primary's own floor the column still renders — header
        // prefix visible, truncation honest — it is NEVER removed from the
        // layout, whatever the budget says (measured: widths 1..7 all show
        // header + rule + 2 data rows with a non-empty header).
        foreach ([1, 2, 3, 5, 6, 7] as $w) {
            $lines = self::lines(Ansi::strip(Table::render(self::ADMIN_COLUMNS, self::adminRows(), 0, $w, 5)));

            self::assertCount(4, $lines, "header + rule + 2 data rows at width {$w}");
            self::assertNotSame('', trim($lines[0]), "the primary column still exists at width {$w}");
        }
    }

    public function testFittingTableIsUntouchedByteIdentical(): void
    {
        // Width 100 pays for all five columns (68 fixed + 8 primary floor + 4
        // gaps): the budget law must not drop or reshape anything, so the
        // header line is pinned exactly as the wide-terminal screens render it.
        $lines = self::lines(Ansi::strip(Table::render(self::ADMIN_COLUMNS, self::adminRows(), 0, 100, 5)));

        self::assertSame(
            'Username                     Email                          Role     Status     Last login          ',
            $lines[0]
        );
        self::assertSame(str_repeat('─', 100), $lines[1]);
    }

    public function testAllFixedTableWithoutAPrimaryIsNeverTrimmed(): void
    {
        // No width-0 column = no primary to protect: the adapter passes the
        // spec through untouched, even when the budget cannot fit it (the
        // renderer's own clip is then the caller's declared problem).
        $columns = [['title' => 'Left', 'width' => 20], ['title' => 'Right', 'width' => 20]];
        $stripped = Ansi::strip(Table::render($columns, [['L', 'R']], 0, 30, 5));

        self::assertStringContainsString('Left', $stripped);
        self::assertStringContainsString('Right', $stripped, 'no primary means no drop ladder');
    }
}

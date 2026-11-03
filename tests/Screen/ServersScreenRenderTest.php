<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Config\ServerEntry;
use Phlix\Console\Screen\ServersScreen;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * [review console__uxdesign, m48] The servers list — the highest-stakes
 * switch in the app — rendered its selection as a bare '>' column with no
 * styling (violating the two-tier focus law every other surface pays:
 * Sidebar, FilterBar, LetterRail, QualityMenu) and hard-clipped long
 * labels/URLs at the panel border without an ellipsis.
 */
final class ServersScreenRenderTest extends TestCase
{
    private const LONG_LABEL = 'The extraordinarily spectacular home media server of the year';

    private const LONG_URL = 'https://very-long-hostname.internal.examplecorp.services.invalid:9443/base';

    /**
     * @return list<string> stripped, border-free panel text rows
     */
    private static function rows(ServersScreen $screen): array
    {
        $rows = [];

        foreach (explode("\n", Ansi::strip($screen->view())) as $line) {
            // Panel geometry: outer Chrome border + inner content border,
            // '││text…  ││'. The text region is cols - 4 cells wide.
            if (preg_match('/^││(.*?)││$/u', $line, $m) === 1 && str_contains($m[1], '(')) {
                $rows[] = rtrim($m[1]);
            }
        }

        return $rows;
    }

    public function testTheCursorRowIsStyledNotJustAByte(): void
    {
        $screen = ServersScreen::create(
            [
                new ServerEntry(id: 's1', label: 'Home', url: 'https://home.tld'),
                new ServerEntry(id: 's2', label: 'Cloud', url: 'https://cloud.tld'),
            ],
            's2',
        );

        // Style composes the focused arm into one SGR: reverse + bold
        // ("\x1b[1;7m"). create() puts the cursor on row 0 ('Home');
        // activeServerId marks Cloud — the CUE and the marker are different
        // facts, and only the cursor row may carry the cue.
        $raw = $screen->view();

        self::assertSame(1, substr_count($raw, "\x1b[1;7m"), 'exactly the selection row is styled');
        self::assertStringContainsString("\x1b[1;7m> Home", $raw, 'the cue rides the cursor row, not the other one');

        // The '>' marker column itself survives for stripped contexts.
        self::assertStringContainsString('> Home', Ansi::strip($raw));
        self::assertStringContainsString('  Cloud [active]', Ansi::strip($raw));
    }

    public function testLongLabelAndUrlEllipsiseInsideThePanelBudget(): void
    {
        $cols = 40;
        $screen = ServersScreen::create(
            [new ServerEntry(id: 's1', label: self::LONG_LABEL, url: self::LONG_URL, hubId: 'h1')],
            's1',
            $cols,
            24,
        );

        $rows = self::rows($screen);
        self::assertNotEmpty($rows);

        foreach ($rows as $row) {
            self::assertStringContainsString('…', $row, 'nothing is silently sliced at the border');
            self::assertLessThanOrEqual($cols - 4, Width::string($row), 'the row fits the Chrome content panel');
        }
    }

    public function testMarkersAndSelectionSurviveEvenTheNarrowestHonestFrame(): void
    {
        $screen = ServersScreen::create(
            [
                new ServerEntry(id: 's1', label: self::LONG_LABEL, url: self::LONG_URL),
                new ServerEntry(id: 's2', label: 'Cloud', url: 'https://cloud.tld'),
            ],
            's1',
            20,
            24,
        );

        $rows = self::rows($screen);
        self::assertNotEmpty($rows);

        $withMarker = array_filter($rows, static fn (string $r): bool => str_contains($r, '[active]'));

        self::assertNotEmpty($withMarker, 'the active marker is never ellipsised away');

        foreach ($rows as $row) {
            self::assertStringContainsString('…', $row, 'the user data is ellipsised, not border-sliced');
            self::assertLessThanOrEqual(16, Width::string($row));
        }
    }

    public function testComfortableGeometryRendersBothFieldsInFull(): void
    {
        $screen = ServersScreen::create(
            [new ServerEntry(id: 's1', label: 'Home', url: 'https://home.tld', hubId: 'h1')],
            's1',
            80,
            24,
        );

        $row = self::rows($screen)[0];

        self::assertStringContainsString('> Home [active] [hub]  (https://home.tld)', $row);
        self::assertStringNotContainsString('…', $row);
    }
}

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Guard the w06/L1 manual/README truth fixes (reviews console__tui-arch #6/#7/#9,
 * ledger CU-064/065/066):
 *
 * (1) The command inventory claimed `--selftest` exercises "boot, config,
 *     container, renderer". runSelftest() in bin/phlix walks four real rungs —
 *     config loaded, object graph wired, HTTP reachable, JSON decoded — and
 *     there is no renderer rung. The cell now names what actually runs.
 * (2) README and the manual said `/` and Ctrl-K / `:` work "from anywhere".
 *     src/App.php gates both on a signed-in session (paletteAndSearchAvailable()
 *     === currentUser() !== null); on the sign-in and server-pick forms the
 *     keys type as characters. Every site now says so.
 * (3) The README render-mode table omitted chafa, which `run` and `poster`
 *     accept (and `frame` rejects) — disclosed in the manual and the dev guide
 *     but invisible from the README. A note below the table closes the gap.
 */
final class ManualClaimsDocsTest extends TestCase
{
    private const README = __DIR__ . '/../../README.md';
    private const MANUAL = __DIR__ . '/../../docs/clients/console.md';

    public function testSelftestCellNamesTheRealLadder(): void
    {
        $manual = self::read(self::MANUAL);

        self::assertStringNotContainsString(
            'boot, config, container, renderer',
            $manual,
            'The selftest rung list must not name a renderer rung that runSelftest() does not have.'
        );
        self::assertMatchesRegularExpression(
            '/config loaded, object graph wired/',
            $manual,
            'The command inventory must lead with the first two real rungs.'
        );
        self::assertMatchesRegularExpression(
            '/no renderer rung/i',
            $manual,
            'The command inventory must state outright that the ladder has no renderer rung.'
        );
    }

    public function testSignInGateOnSearchAndPaletteIsDisclosed(): void
    {
        $readme = self::read(self::README);
        $manual = self::read(self::MANUAL);

        self::assertStringNotContainsString(
            'from anywhere',
            $readme,
            'README must not claim `/`-and-palette reach "from anywhere" — App.php gates '
            . 'both on a signed-in session (paletteAndSearchAvailable()).'
        );
        self::assertSame(
            2,
            substr_count($readme, "once you're signed in"),
            'Both README gate sites (run-it comment, status section) must carry the signed-in qualifier.'
        );
        self::assertStringContainsString(
            'signed-in keys',
            $readme,
            'The README key list must qualify `/` and the palette as signed-in keys.'
        );
        self::assertStringContainsString(
            "Global search (or filter within a grid) — once you're signed in",
            $manual,
            'The manual search row must disclose the sign-in gate.'
        );
        self::assertStringContainsString(
            'Open command palette — same sign-in gate as',
            $manual,
            'The manual palette row must point at the same gate.'
        );
    }

    public function testReadmeRenderModesDiscloseChafa(): void
    {
        $readme = self::read(self::README);

        self::assertMatchesRegularExpression(
            '/`chafa` is accepted by `run` and `poster`/',
            $readme,
            'The README render-mode section must disclose chafa the way the manual and dev guide do.'
        );
        self::assertMatchesRegularExpression(
            '/`frame` rejects it as a usage/',
            $readme,
            'The chafa note must carry the frame-side rejection, matching bin/phlix FRAME_MODES.'
        );
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read $path");
        }
        return $contents;
    }
}

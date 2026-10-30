<?php

/**
 * Guard against the M-CONSOLE-04 class (review console__a11y): seven screens
 * implement the grid pager keys (PgUp/PgDn page, Home/End first/last) yet the
 * keybinding docs taught only the arrow keys — a keyboard user could not
 * discover the fast paths out of a 5,000-item grid, and screen-reader prose
 * following the tables would keep recommending the slow one.
 *
 * Doctrine, per GridKeysDocsTest: the census is DERIVED from the code (the
 * KeyType arms the screens actually match), never restated from prose. Two
 * laws:
 *
 * 1. The derived pager-handler screen census is non-empty and counts at least
 *    the shipped floor — if handler arms silently disappear, docs must NOT
 *    stay green by inheriting a stale census.
 * 2. All four tokens (`PgUp`, `PgDn`, `Home`, `End`, back-ticked) appear in
 *    BOTH the README keys summary and the manual's Keybindings section.
 *    Docs may add vocabulary freely; they may drop it only in lockstep with
 *    the handlers.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GridPagerKeysDocsTest extends TestCase
{
    /** Screens shipping all four pager arms as of the W-min21 cure. */
    private const EXPECTED_PAGER_SCREEN_COUNT = 7;

    /**
     * @return list<string> repository-relative screen files with a full pager arm set
     */
    private static function pagerScreens(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Screen';
        $dir = opendir($root);
        if ($dir === false) {
            throw new RuntimeException("Unreadable {$root} — run from the repository checkout.");
        }

        $screens = [];
        while (($entry = readdir($dir)) !== false) {
            if (!str_ends_with($entry, '.php')) {
                continue;
            }
            $source = (string) file_get_contents($root . '/' . $entry);
            $hasAll = true;
            foreach (['PageUp', 'PageDown', 'Home', 'End'] as $arm) {
                if (strpos($source, 'KeyType::' . $arm) === false) {
                    $hasAll = false;
                    break;
                }
            }
            if ($hasAll) {
                $screens[] = 'src/Screen/' . $entry;
            }
        }
        closedir($dir);
        sort($screens);

        return $screens;
    }

    public function testThePagerHandlerCensusIsStillDerivedAndComplete(): void
    {
        $screens = self::pagerScreens();

        self::assertNotEmpty($screens, 'no screen matched a full PageUp/PageDown/Home/End arm set — the walk broke or the handlers moved; the docs pin below must be updated deliberately, not silently.');
        self::assertCount(
            self::EXPECTED_PAGER_SCREEN_COUNT,
            $screens,
            'pager-key handler census drifted from ' . implode(', ', $screens)
            . ' — new pager screens need the docs rows too (law 2), removed ones need the docs moved in lockstep.'
        );
    }

    public function testThePagerKeysAreDocumentedInReadmeAndManual(): void
    {
        $readme = self::repoFile('README.md');
        $manual = self::repoFile('docs/clients/console.md');
        $keybindings = self::keybindingsSection($manual);

        $missing = [];
        foreach (['PgUp', 'PgDn', 'Home', 'End'] as $key) {
            if (!str_contains($readme, '`' . $key . '`')) {
                $missing[] = 'README.md `' . $key . '`';
            }
            if (!str_contains($keybindings, '`' . $key . '`')) {
                $missing[] = 'docs/clients/console.md Keybindings `' . $key . '`';
            }
        }

        self::assertSame([], $missing, 'grid pager keys the screens implement but the docs omit (review M-CONSOLE-04): ' . implode(', ', $missing));
    }

    /**
     * The manual's Keybindings chapter (from its `### Keybindings` header to
     * the next `### ` header), so pager prose elsewhere cannot satisfy law 2.
     */
    private static function keybindingsSection(string $manual): string
    {
        $start = strpos($manual, '### Keybindings');
        if ($start === false) {
            throw new RuntimeException('docs/clients/console.md lost its "### Keybindings" section.');
        }
        $rest = substr($manual, $start + strlen('### Keybindings'));
        $end = strpos($rest, "\n### ");

        return $end === false ? $rest : substr($rest, 0, $end);
    }

    private static function repoFile(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException("Unreadable {$relative} — run from the repository root.");
        }

        return $contents;
    }
}

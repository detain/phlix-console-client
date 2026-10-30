<?php

/**
 * Guard against the M-CONSOLE-30 class (review console__docmaint): the shipped
 * home/library grid keys lived only in the in-app hint strings — the README's
 * keys summary and the manual's Keybindings tables omitted them, so a reader
 * never learned `R` refresh, `s` shuffle, `c` clear-filter, `q` quit existed.
 *
 * Doctrine, per PlayerKeyDocsTest and ScreenInventoryDocsTest: the key census
 * is DERIVED from the hint literals the screens actually render (regex over
 * source), never restated from prose. Two laws:
 *
 * 1. The derived single-key census (BrowseScreen SIDEBAR_HINT + railsHint()
 *    literal, LibraryScreen HINT) is non-empty — if a hint rewrite stops
 *    parsing here, the pin demands a deliberate update, not silent silence.
 * 2. Every derived key appears back-ticked in BOTH the README keys summary
 *    and the manual's Keybindings section. Docs may add keys freely; docs may
 *    drop a key only in lockstep with the hint that ships it.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GridKeysDocsTest extends TestCase
{
    public function testTheHintLiteralsStillYieldAGridKeyCensus(): void
    {
        self::assertNotEmpty(
            self::derivedKeys(),
            'no single-character keys parsed from the home/library hint literals — '
            . 'the hint format changed shape and this pin must be updated deliberately.'
        );
    }

    public function testEveryShippedGridKeyIsDocumentedInReadmeAndManual(): void
    {
        $readme = self::repoFile('README.md');
        $manual = self::keybindingsSection(self::repoFile('docs/clients/console.md'));

        $missing = [];
        foreach (self::derivedKeys() as $key) {
            if (!str_contains($readme, '`' . $key . '`')) {
                $missing[] = 'README.md `' . $key . '`';
            }
            if (!str_contains($manual, '`' . $key . '`')) {
                $missing[] = 'docs/clients/console.md `' . $key . '`';
            }
        }

        self::assertSame(
            [],
            $missing,
            'grid keys the shipped hints advertise but the docs omit (review M-CONSOLE-30): '
            . implode(', ', $missing)
        );
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * The single-character grid keys advertised by the shipped hints.
     *
     * @return list<string>
     */
    private static function derivedKeys(): array
    {
        $hints = [
            self::singleQuotedConst(self::repoFile('src/Screen/BrowseScreen.php'), 'SIDEBAR_HINT'),
            self::doubleQuotedRailsHint(self::repoFile('src/Screen/BrowseScreen.php')),
            self::singleQuotedConst(self::repoFile('src/Screen/LibraryScreen.php'), 'HINT'),
        ];

        $keys = [];
        foreach ($hints as $hint) {
            if (preg_match_all('/(?:^|\s)([A-Za-z])  (?=\S)/', $hint, $matches)) {
                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    private static function singleQuotedConst(string $source, string $name): string
    {
        if (!preg_match("/private const $name = '([^']+)';/", $source, $match)) {
            throw new RuntimeException("could not parse the $name literal — this pin and the hints move together.");
        }

        return $match[1];
    }

    private static function doubleQuotedRailsHint(string $source): string
    {
        if (!preg_match('/return "(↑↓  rails[^"]+)";/', $source, $match)) {
            throw new RuntimeException(
                'could not parse the railsHint() return literal — this pin and the hints move together.'
            );
        }

        return $match[1];
    }

    private static function keybindingsSection(string $manual): string
    {
        $start = strpos($manual, "\n### Keybindings\n");
        if ($start === false) {
            throw new RuntimeException('the manual lost its "### Keybindings" section.');
        }
        $rest = substr($manual, (int) $start + strlen("\n### Keybindings\n"));
        $break = strpos($rest, "\n## ");

        return $break === false ? $rest : substr($rest, 0, $break);
    }

    private static function repoFile(string $relative): string
    {
        $contents = @file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        if ($contents === false) {
            throw new RuntimeException('cannot read repo file: ' . $relative);
        }

        return $contents;
    }
}

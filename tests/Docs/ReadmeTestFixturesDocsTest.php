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
 * Guard against the M-CONSOLE-44 class (review console__ipd): the README Test
 * section once generalized "the poster test synthesizes its own image with
 * `gd`, so the suite needs no fixtures" while the route gate test loads a
 * vendored fixture — `tests/fixtures/server-route-manifest.json`, a
 * byte-for-byte copy of the contracts route manifest. The sentence was true
 * of image/video synthesis only; as a general claim it sent contributors to
 * hunt for a suite with a fixture in plain sight.
 *
 * Doctrine, per StaticAnalysisClaimsDocsTest: the fixture inventory is
 * RE-DERIVED from the tree — never restated from prose. Three laws, bidir-
 * ectional:
 *
 * 1. Every file in tests/fixtures/ is named in the README Test section.
 *    Adding a fixture without telling the README reddens here.
 * 2. Every `tests/fixtures/<name>` path the Test section names exists on
 *    disk. Renaming or deleting a fixture without moving the prose reddens
 *    here.
 * 3. While the directory is non-empty, the Test section must not claim the
 *    suite "needs no fixtures". Reverting the cure reddens here; if the
 *    directory ever becomes genuinely empty the prohibition lifts with it —
 *    the law tracks the tree, not a snapshot.
 *
 * Historical CHANGELOG entries are exempt forever (history is verbatim);
 * this suite never reads it.
 */
final class ReadmeTestFixturesDocsTest extends TestCase
{
    /** Section start marker of the "## Test" block in README.md. */
    private const README_SECTION_START = "## Test\n";

    /** Section end marker: the block is followed by "## Static analysis". */
    private const README_SECTION_END = "\n## Static analysis";

    private const FIXTURES_DIR = 'tests/fixtures';

    /** The forbidden generalization while fixtures exist (M-CONSOLE-44). */
    private const OVERSTATEMENT_PATTERN = '/needs no fixtures/i';

    public function testTheTestSectionNamesEveryVendoredFixtureFile(): void
    {
        $section = self::readmeSection();
        $missing = [];

        foreach (self::fixtureFiles() as $name) {
            if (strpos($section, self::FIXTURES_DIR . '/' . $name) === false) {
                $missing[] = $name;
            }
        }

        self::assertSame(
            [],
            $missing,
            'the README Test section does not name vendored fixture files that '
            . 'tests/fixtures/ actually carries (review M-CONSOLE-44): '
            . implode(', ', $missing)
            . ' — say what the suite loads instead of claiming it needs none.'
        );
    }

    public function testEveryFixturePathNamedInTheTestSectionExistsOnDisk(): void
    {
        $section = self::readmeSection();
        preg_match_all('#tests/fixtures/([\w.-]+)#', $section, $matches);

        $ghosts = [];
        foreach (array_unique($matches[1] ?? []) as $name) {
            if (!is_file(self::FIXTURES_DIR . '/' . $name)) {
                $ghosts[] = $name;
            }
        }

        self::assertSame(
            [],
            $ghosts,
            'the README Test section cites fixture files that do not exist on disk: '
            . implode(', ', $ghosts)
            . ' — move the prose together with any rename or removal.'
        );
    }

    public function testTheTestSectionNeverClaimsNoFixturesWhileTheDirectoryIsNotEmpty(): void
    {
        $files = self::fixtureFiles();
        if ($files === []) {
            self::markTestSkipped(
                'tests/fixtures/ is empty; the "needs no fixtures" phrasing would '
                . 'be honest again and this prohibition lifts with the directory.'
            );
        }

        $section = self::readmeSection();

        self::assertSame(
            0,
            preg_match(self::OVERSTATEMENT_PATTERN, $section),
            'the README Test section re-claims "needs no fixtures" while '
            . 'tests/fixtures/ ships ' . count($files) . ' file(s) (review M-CONSOLE-44). '
            . 'Qualify the sentence: no BINARY fixtures need downloading; the '
            . 'vendored route-manifest JSON lives in tests/fixtures/.'
        );
    }

    /** @return list<string> basenames of the files tests/fixtures/ carries. */
    private static function fixtureFiles(): array
    {
        if (!is_dir(self::FIXTURES_DIR)) {
            throw new RuntimeException('Expected ' . self::FIXTURES_DIR . '/ to exist; the gate test loads it.');
        }

        $names = array_values(array_filter(
            scandir(self::FIXTURES_DIR) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
                && is_file(self::FIXTURES_DIR . '/' . $entry)
        ));
        sort($names);

        return $names;
    }

    private static function readmeSection(): string
    {
        $text = self::read('README.md');

        $start = strpos($text, self::README_SECTION_START);
        if ($start === false) {
            throw new RuntimeException('Test section start marker not found: ' . self::README_SECTION_START);
        }

        $start += strlen(self::README_SECTION_START);
        $end = strpos($text, self::README_SECTION_END, $start);
        if ($end === false) {
            throw new RuntimeException('Test section end marker not found: ' . self::README_SECTION_END);
        }

        return substr($text, $start, $end - $start);
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents($relative);
        if ($contents === false) {
            throw new RuntimeException('Cannot read repo file: ' . $relative);
        }

        return $contents;
    }
}

<?php

/**
 * Guard against the M-CONSOLE-26 class (review console__docmaint): README.md
 * once claimed "no baseline and no suppressions" for the PHPStan level-9 leg
 * while `src/` carried inline phpstan-ignore comments (five at the time of
 * writing, all `property.onlyWritten` on write-only SyncPlay state).
 *
 * The enforced half of the claim — no baseline FILE and no `ignoreErrors` in
 * either config — is still true, so it is pinned here too (the docs must be
 * honest in BOTH directions, same doctrine as RuntimeRequirementsDocsTest).
 *
 * The suppression census is RE-DERIVED from the tree — never restated from
 * prose. Planting a new suppression, deleting one, or rewording the README
 * table without the code reddens this suite and its messages direct the
 * README table, the docs/dev/client-console.md pointer, and this pin to move
 * together.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class StaticAnalysisClaimsDocsTest extends TestCase
{
    /** Section markers of the "## Static analysis" block in README.md. */
    private const README_SECTION_START = "## Static analysis\n";

    private const README_SECTION_END = "\n## License";

    /**
     * A data row of the suppression table: a pipe row whose first cell is a
     * backticked `Class::$property` token.
     */
    private const TABLE_ROW_PATTERN = '/^\| `\w+::\$\w+/m';

    /** `Class::$property` tokens as README rows and tree sites share them. */
    private const TOKEN_PATTERN = '/([A-Za-z0-9]+)::\$([A-Za-z_][A-Za-z0-9_]*)/';

    public function testTheSuppressionCensusIsFullyClassifiable(): void
    {
        $sites = self::scannedSuppressions();

        // Classification itself fail-louds (see suppressionSites()), so here
        // the census is provably non-empty and every entry has a property
        // name and an identifier — an unclassifiable site must never be
        // silently skipped.
        self::assertNotEmpty($sites, 'expected src/ suppressions to exist while the docs pin assumes them');

        foreach ($sites as $site) {
            self::assertNotSame('', $site['property'], 'unclassified suppression target at ' . $site['file']);
            self::assertNotSame('', $site['identifier'], 'suppression without an identifier at ' . $site['file']);
        }
    }

    public function testTheReadmeTableNamesEverySuppressionSiteAndNothingElse(): void
    {
        $sites = self::scannedSuppressions();
        $section = self::readmeSection();

        $expected = [];
        foreach ($sites as $site) {
            $expected[] = $site['token'];
        }

        sort($expected);

        preg_match_all(self::TOKEN_PATTERN, $section, $named);
        $actual = [];
        foreach ($named[1] as $i => $class) {
            $actual[] = $class . '::$' . $named[2][$i];
        }

        $actual = array_values(array_unique($actual));
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'the README "Static analysis" suppression table no longer matches the '
            . 'tree: ' . count($expected) . ' suppression(s) live in src/ — move the '
            . 'table (and docs/dev/client-console.md\'s pointer) with the code.'
        );

        preg_match_all(self::TABLE_ROW_PATTERN, $section, $rows);

        self::assertSame(
            count($sites),
            count($rows[0]),
            'the README table must carry exactly one row per suppression site.'
        );
    }

    public function testTheReadmeStatesTheLiveCountAndEveryIdentifier(): void
    {
        $sites = self::scannedSuppressions();
        $section = self::readmeSection();

        self::assertStringContainsString(
            count($sites) . ' inline suppressions',
            $section,
            'README.md no longer states the live suppression count (' . count($sites)
            . ') — restate it from the tree, never from memory.'
        );

        foreach (array_unique(array_column($sites, 'identifier')) as $identifier) {
            self::assertStringContainsString(
                $identifier,
                $section,
                'README.md no longer names the suppressed identifier ' . $identifier
            );
        }
    }

    public function testNoStaticAnalysisSiteClaimsThereAreNoSuppressions(): void
    {
        $normalizedReadme = (string) preg_replace('/\s+/', ' ', self::readmeSection());

        self::assertDoesNotMatchRegularExpression(
            '/no\s+suppressions/i',
            $normalizedReadme,
            'src/ carries inline suppressions, yet README.md claims there are none.'
        );

        $normalizedConsole = (string) preg_replace('/\s+/', ' ', self::read('docs/dev/client-console.md'));

        self::assertDoesNotMatchRegularExpression(
            '/no\s+baseline[,\s]*(?:and\s+)?no\s+suppressions/i',
            $normalizedConsole,
            'src/ carries inline suppressions, yet docs/dev/client-console.md claims there are none.'
        );
    }

    public function testTheNoBaselineHalfOfTheClaimStillHolds(): void
    {
        self::assertFalse(
            file_exists('phpstan-baseline.neon'),
            'a phpstan-baseline.neon appeared: README/docs claim "no baseline file" — flip prose AND this pin together.'
        );

        foreach (['phpstan.neon', 'phpstan-tests.neon'] as $config) {
            $live = [];
            foreach (preg_split('/\R/', self::read($config)) ?: [] as $line) {
                if (!str_starts_with(ltrim($line), '#')) {
                    $live[] = $line;
                }
            }

            self::assertStringNotContainsString(
                'ignoreErrors',
                implode("\n", $live),
                $config . ' gained ignoreErrors: the README suppression-posture claim (and the table) must move.'
            );
        }

        $section = self::readmeSection();

        foreach (['phpstan.neon', 'phpstan-tests.neon', 'StaticAnalysisClaimsDocsTest'] as $token) {
            self::assertStringContainsString(
                $token,
                $section,
                'the README static-analysis section no longer names ' . $token
            );
        }
    }

    /**
     * Scan src/ for every inline phpstan-ignore comment and classify its target.
     *
     * @return list<array{file: string, token: string, property: string, identifier: string}>
     */
    private static function scannedSuppressions(): array
    {
        $sites = [];

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator('src', RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && substr($entry->getFilename(), -4) === '.php') {
                $files[] = str_replace('\\', '/', $entry->getPathname());
            }
        }
        sort($files);

        foreach ($files as $file) {
            $lines = file($file) ?: throw new RuntimeException('Cannot read ' . $file);
            $class = basename($file, '.php');

            foreach ($lines as $index => $line) {
                if (!preg_match('/@phpstan-ignore(-next-line)?(?:\s+([A-Za-z0-9._]+))?/', $line, $match)) {
                    continue;
                }

                $declaration = ($match[1] ?? '') === '-next-line'
                    ? (string) ($lines[$index + 1] ?? '')
                    : $line;

                if (!preg_match('/^\s*(?:private|public|protected)[^;{(]*\$([A-Za-z_][A-Za-z0-9_]*)/', $declaration, $declared)) {
                    // The pin covers property suppressions only. A new
                    // suppression shape (method, return, ...) must extend
                    // this classification deliberately, not slip through.
                    throw new RuntimeException(
                        'Unclassifiable @phpstan-ignore at ' . $file . ':' . ($index + 1)
                        . ' — target line is not a property declaration; extend '
                        . 'StaticAnalysisClaimsDocsTest deliberately if a new suppression kind was added.'
                    );
                }

                $sites[] = [
                    'file' => $file . ':' . ($index + 1),
                    'token' => $class . '::$' . $declared[1],
                    'property' => $declared[1],
                    'identifier' => $match[2] ?? '',
                ];
            }
        }

        return $sites;
    }

    private static function readmeSection(): string
    {
        $text = self::read('README.md');

        $start = strpos($text, self::README_SECTION_START);
        if ($start === false) {
            throw new RuntimeException('Static analysis section start marker not found: ' . self::README_SECTION_START);
        }

        $start += strlen(self::README_SECTION_START);
        $end = strpos($text, self::README_SECTION_END, $start);
        if ($end === false) {
            throw new RuntimeException('Static analysis section end marker not found: ' . self::README_SECTION_END);
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

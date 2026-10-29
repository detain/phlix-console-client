<?php

/**
 * Guard against the M-CONSOLE-27 class (review console__ipd): docs/steps/
 * ci-baseline-C9.md is the document a contributor is pointed at for "what must
 * stay green". It once asserted phantom gates (coverage floors that no step
 * enforces, a uniform bare-PSR12 phpcs story, a dropped grep pattern, a smoke
 * leg that never ran) and — after the M-CONSOLE-53 currency pass re-anchored
 * every `path:line` citation to commit 759d7c5 — later workflow edits (7b08db5)
 * shifted build.yml by +2/+4 lines and silently stale-ed five coordinates.
 *
 * The pin works like the doc promises: every gate heading must match a real
 * job key in the workflow files; every coordinate in the anchor table below is
 * RE-DERIVED from the file on disk (search for the step/job/command line, take
 * its live line number) and must be cited in the doc at exactly that line.
 * When a workflow drifts, the derived number moves, the doc's stale citation no
 * longer matches, and this suite reddens — the doc and this pin must move in
 * the same change as the workflow.
 *
 * The historical-record posture (removed coverage floors labelled as history,
 * never re-asserted as live) is pinned in both directions: the doc must keep
 * saying the floor is not enforced, and phpunit.xml.dist must still lack the
 * `<threshold>` block that claim depends on.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CiBaselineClaimsDocsTest extends TestCase
{
    private const DOC = 'docs/steps/ci-baseline-C9.md';

    /** Citation short name as used in the doc => repo path of the file cited. */
    private const FILES = [
        'ci.yml' => '.github/workflows/ci.yml',
        'build.yml' => '.github/workflows/build.yml',
        'deps.yml' => '.github/workflows/deps.yml',
        'phpunit.xml.dist' => 'phpunit.xml.dist',
        'phpstan.neon' => 'phpstan.neon',
        'phpstan-tests.neon' => 'phpstan-tests.neon',
        'composer.json' => 'composer.json',
    ];

    /**
     * Every entry: [file, startRegex, mode, occurrence, endRegex, endOccurrence].
     *
     * mode 'start'   - the doc must carry a citation whose START equals the
     *                  live line of the (occurrence-th) startRegex match.
     * mode 'contains' - some cited range must CONTAIN the live line.
     * mode 'exact'   - the doc must cite the exact [start, end] range derived
     *                  from the two anchors.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int, 4: string|null, 5: int}>
     */
    private const COORDINATE_ANCHORS = [
        // ---- ci.yml (the "Current CI Gates" section) ----
        ['ci.yml', '/php-version: \[\'8\.3\'/', 'start', 1, null, 1],
        ['ci.yml', '/coverage: pcov/', 'start', 1, null, 1],
        ['ci.yml', '/name: Install ffmpeg/', 'exact', 1, '/apt-get install -y ffmpeg/', 1],
        ['ci.yml', '/run: vendor\/bin\/phpunit\b/', 'start', 1, null, 1],
        ['ci.yml', '/run: vendor\/bin\/phpstan analyse --no-progress/', 'start', 1, null, 1],
        ['ci.yml', '/phpstan-tests\.neon --no-progress/', 'start', 1, null, 1],
        ['ci.yml', '/- name: Assert PHPCS is actually installed/', 'exact', 1, '/vendor\/bin\/phpcs --version/', 1],
        ['ci.yml', '/- name: Run PHPCS on src\//', 'exact', 1, '/re-arms the corpse this repair buried/', 1],
        ['ci.yml', '/--standard=phpcs\.xml --colors/', 'start', 1, null, 1],
        ['ci.yml', '/- name: Run PHPCS on tests\//', 'exact', 1, '/why exactly one sniff is excluded/', 1],
        ['ci.yml', '/--standard=phpcs-tests\.xml --colors/', 'start', 1, null, 1],
        ['ci.yml', '/php-version: \'8\.3\'/', 'start', 1, null, 1],
        ['ci.yml', '/php scripts\/check-i18n-hardcoded\.php/', 'start', 1, null, 1],
        ['ci.yml', '/do NOT match bare/', 'exact', 1, '/ORIGINAL_NODE/', 1],
        ['ci.yml', '/PATCHED=\$\(grep -rEl/', 'start', 1, null, 1],
        ['ci.yml', '/needs: test/', 'start', 1, null, 1],
        ['ci.yml', '/run: \|\n          # The selftest requires/', 'exact', 1, '/php bin\/phlix run --selftest/', 1],
        ['ci.yml', '/PHLIX_SERVER_URL=.*php bin\/phlix/', 'start', 1, null, 1],
        ['ci.yml', '/development-dependency exclusion/', 'exact', 1, '/slice is not neutered/', 1],
        ['ci.yml', '/php-version: \'8\.3\'/', 'start', 2, null, 1],
        ['ci.yml', '/php scripts\/security-audit-check\.php/', 'start', 1, null, 1],
        // ---- build.yml (the "Build Gates" section) ----
        ['build.yml', '/composer install --no-dev/', 'start', 1, null, 1],
        ['build.yml', '/run: box compile/', 'start', 1, null, 1],
        ['build.yml', '/- name: Smoke-test the phar/', 'exact', 1, '/php build\/phlix\.phar run --selftest/', 1],
        ['build.yml', '/php build\/phlix\.phar --version/', 'start', 1, null, 1],
        ['build.yml', '/phar bogus \|\| test \$\? -eq 2/', 'start', 1, null, 1],
        ['build.yml', '/php -S 127\.0\.0\.1:8099/', 'exact', 1, '/php build\/phlix\.phar run --selftest/', 1],
        ['build.yml', '/- name: Generate SHA256SUMS/', 'exact', 1, '/cat SHA256SUMS/', 1],
        ['build.yml', '/- name: Upload phar artifact/', 'exact', 1, '/if-no-files-found: error/', 2],
        ['build.yml', '/php-version: \'8\.3\'/', 'start', 1, null, 1],
        ['build.yml', '/- name: Verify SHA256SUMS/', 'start', 2, null, 1],
        ['build.yml', '/- name: Tag-vs-version consistency check/', 'start', 1, null, 1],
        ['build.yml', '/- name: Publish release/', 'start', 1, null, 1],
        // ---- enforcement configs the gate sections describe ----
        ['phpunit.xml.dist', '/failOnWarning="true"/', 'exact', 1, '/failOnRisky="true"/', 1],
        ['phpstan.neon', '/level: 9/', 'start', 1, null, 1],
        ['phpstan.neon', '/^ {8}- src$/m', 'start', 1, null, 1],
        ['phpstan.neon', '/1000\+ level-9/', 'contains', 1, null, 1],
        ['phpstan-tests.neon', '/level: 2/', 'start', 1, null, 1],
        ['composer.json', '/sugar-core": "dev-master"/', 'exact', 1, '/sugar-overlay": "dev-master"/', 1],
        ['composer.json', '/sugarcraft\/sugar-testing": "dev-master"/', 'start', 1, null, 1],
        // ---- deps.yml (Dependencies section) ----
        ['deps.yml', '/writes master from this workflow/', 'contains', 1, null, 1],
        ['deps.yml', '/^\s+schedule:$/m', 'exact', 1, '/cron: \'0 6 \* \* 1\'/', 1],
        ['deps.yml', '/- name: Run PHPStan\b/', 'start', 1, null, 1],
        ['deps.yml', '/Open pull request for the proposed lock/', 'contains', 1, null, 1],
    ];

    public function testEveryCiJobIsADocumentedGateHeadingAndViceVersa(): void
    {
        $doc = self::read(self::DOC);
        $section = self::slice($doc, '## Current CI Gates', '## Build Gates');

        self::assertSame(
            self::jobNames('.github/workflows/ci.yml'),
            self::headingNames($section),
            'docs/steps/ci-baseline-C9.md "Current CI Gates" no longer matches the job '
            . 'inventory of .github/workflows/ci.yml — a gate was renamed, added, removed, '
            . 'or the doc invented one that is not enforced. Move the doc section with the workflow.'
        );
    }

    public function testEveryBuildJobIsADocumentedGateHeadingWithItsLiveRange(): void
    {
        $doc = self::read(self::DOC);
        $section = self::slice($doc, '## Build Gates', '## PHP Version Matrix');
        $text = self::read('.github/workflows/build.yml');

        $jobs = self::jobPositions($text);
        $names = [];
        foreach ($jobs as $job) {
            $names[] = $job['name'];

            // A job block runs from its key line to two lines before the next
            // key (blank separator), or to the last non-blank line of the file.
            self::assertMatchesRegularExpression(
                '/### ' . preg_quote($job['name'], '/') . ' job \(`build\.yml:'
                . $job['line'] . '-' . $job['end'] . '`/',
                $section,
                'the "### ' . $job['name'] . ' job" heading no longer cites the live '
                . 'range build.yml:' . $job['line'] . '-' . $job['end'] . ' — build.yml moved; '
                . 'update the doc coordinate in the same change.'
            );
        }

        self::assertSame(
            $names,
            self::headingNames($section),
            'the doc Build Gates headings and build.yml job keys drifted apart (order included).'
        );
    }

    public function testEveryCitedCoordinateMatchesTheFileOnDisk(): void
    {
        $citations = self::docCitations();

        foreach (self::COORDINATE_ANCHORS as [$file, $pattern, $mode, $occurrence, $endPattern, $endOccurrence]) {
            $line = self::liveLine($file, $pattern, $occurrence);

            if ($mode === 'exact') {
                $end = self::liveLine($file, (string) $endPattern, $endOccurrence);
                self::assertContains(
                    [$line, $end],
                    $citations[$file],
                    self::driftMessage($file, $pattern) . ' must be cited as `' . $file . ':'
                    . $line . '-' . $end . '` (live range on disk); the doc is stale.'
                );
                continue;
            }

            if ($mode === 'contains') {
                $covered = false;
                foreach ($citations[$file] as [$start, $stop]) {
                    if ($start <= $line && $line <= $stop) {
                        $covered = true;
                        break;
                    }
                }
                self::assertTrue(
                    $covered,
                    self::driftMessage($file, $pattern) . ' now sits at line ' . $line
                    . ', outside every `' . $file . ':N-M` citation in the doc.'
                );
                continue;
            }

            // 'start': some citation must BEGIN exactly at the live line.
            self::assertContains(
                $line,
                array_map(static fn (array $c): int => $c[0], $citations[$file]),
                self::driftMessage($file, $pattern) . ' now sits at line ' . $line
                . '; the doc must cite `' . $file . ':' . $line . '` (or `' . $file . ':'
                . $line . '-...`).'
            );
        }
    }

    public function testTheDocDoesNotReAssertRemovedGatesAsLive(): void
    {
        // The doc is hard-wrapped prose; compare on whitespace-normalized text.
        $doc = (string) preg_replace('/\s+/', ' ', self::read(self::DOC));

        // The filed major's four phantom-gate fictions, pinned in cured state.
        foreach (
            [
            'No coverage threshold is enforced today.',
            '**Not** bare',
            'Bare `ORIGINAL` is deliberately **not** matched',
            '**no `help` invocation**',
            'no coverage gate runs in CI today',
            ] as $phrase
        ) {
            self::assertStringContainsString(
                $phrase,
                $doc,
                'ci-baseline-C9.md lost the sentence "' . $phrase . '" — if the workflow '
                . 'truly gained that gate, move the doc AND this pin together.'
            );
        }

        // The doc's claims depend on these workflow truths still holding:
        $ci = self::read('.github/workflows/ci.yml');
        self::assertStringContainsString('--standard=phpcs.xml --colors src/', $ci);
        self::assertStringContainsString('--standard=phpcs-tests.xml --colors tests/', $ci);

        $phpunit = self::read('phpunit.xml.dist');
        self::assertStringNotContainsString(
            '<threshold',
            $phpunit,
            'a <threshold> block came back into phpunit.xml.dist: the "no coverage floor '
            . 'is enforced today" prose (and this pin) must be re-written to the new truth.'
        );
    }

    public function testTheHeaderScopesGateClaimsAndLabelsTheHistoricalRecords(): void
    {
        $doc = self::read(self::DOC);

        // M-CONSOLE-27 surviving nuance: the old blanket "All items below are
        // enforced in CI" header sat over dated record sections. The header now
        // scopes the enforcement claim and the two history sections keep their
        // verbatim-record labels.
        self::assertStringContainsString('point-in-time records, not', $doc);
        self::assertDoesNotMatchRegularExpression(
            '/all items below are enforced in ci/i',
            $doc,
            'the blanket "all items below are enforced in CI" header is back over sections '
            . 'the doc itself labels historical — keep the scoped phrasing.'
        );
        self::assertStringContainsString('*(Historical record as of C9;', $doc);
        self::assertStringContainsString('(Dated snapshot of the measurement', $doc);
    }

    /**
     * Parse every `file:N` / `file:N-M` / bare `:N` (continues the last cited
     * file) citation out of the doc, restricted to the FILES inventory.
     *
     * @return array<string, list<array{0: int, 1: int}>>
     */
    private static function docCitations(): array
    {
        $doc = self::read(self::DOC);

        if (
            !preg_match_all(
                '/`(?:(?<file>[A-Za-z0-9._\/-]+))?:(?<nums>(?:\d+|\d+-\d+)(?:,(?:\d+|\d+-\d+))*)`/',
                $doc,
                $found,
                PREG_SET_ORDER
            )
        ) {
            throw new RuntimeException(
                'ci-baseline-C9.md carries no `file:line` citations at all — '
                . 'the currency contract died.'
            );
        }

        $citations = array_fill_keys(array_keys(self::FILES), []);
        $lastFile = null;

        foreach ($found as $match) {
            $named = is_string($match['file']) && $match['file'] !== '' ? $match['file'] : $lastFile;
            if ($named === null || !array_key_exists($named, self::FILES)) {
                continue; // not a citation of a tracked file (does not move the cursor)
            }
            $lastFile = $named;

            foreach (explode(',', $match['nums']) as $number) {
                [$start, $end] = array_pad(explode('-', $number, 2), 2, null);
                $citations[$named][] = [(int) $start, (int) ($end ?? $start)];
            }
        }

        return $citations;
    }

    /** @return list<string> */
    private static function jobNames(string $path): array
    {
        $names = [];
        foreach (self::jobPositions(self::read($path)) as $job) {
            $names[] = $job['name'];
        }

        return $names;
    }

    /**
     * Two-space-indented job keys after the `jobs:` marker, with block ranges.
     *
     * @return list<array{name: string, line: int, end: int}>
     */
    private static function jobPositions(string $yaml): array
    {
        // Slice at `jobs:` — the top-level `on:` block has trigger keys such as
        // `push:` with the same shape as job keys.
        $marker = strpos($yaml, "\njobs:\n");
        if ($marker === false) {
            throw new RuntimeException('no `jobs:` block found in a workflow file — did its shape change?');
        }
        $jobsText = substr($yaml, $marker + 1);
        $offset = substr_count($yaml, "\n", 0, $marker + 1);

        $lines = preg_split('/\R/', $jobsText) ?: [];
        $jobs = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^  ([a-z][a-z0-9-]*):$/', $line, $match)) {
                $jobs[] = ['name' => $match[1], 'line' => $index + 1, 'end' => 0];
            }
        }

        if ($jobs === []) {
            throw new RuntimeException('no job keys found in a workflow file — did its shape change?');
        }

        $lastContent = 0;
        foreach ($lines as $index => $line) {
            if (trim($line) !== '') {
                $lastContent = $index + 1;
            }
        }

        foreach ($jobs as $i => $job) {
            $relativeEnd = isset($jobs[$i + 1])
                ? $jobs[$i + 1]['line'] - 2
                : $lastContent;
            $jobs[$i]['line'] += $offset;
            $jobs[$i]['end'] = $relativeEnd + $offset;
        }

        return $jobs;
    }

    /**
     * First token of every `### heading` inside a doc section, in order.
     *
     * @return list<string>
     */
    private static function headingNames(string $section): array
    {
        $names = [];
        if (preg_match_all('/^### ([A-Za-z0-9-]+)/m', $section, $found)) {
            foreach ($found[1] as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function liveLine(string $file, string $pattern, int $occurrence): int
    {
        $text = self::read(self::FILES[$file]);

        if (!preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException(
                'anchor ' . $pattern . ' vanished from ' . self::FILES[$file]
                . ' — if the workflow step changed, move ci-baseline-C9.md AND this pin in the same change.'
            );
        }

        if (!isset($found[0][$occurrence - 1])) {
            throw new RuntimeException(
                'anchor ' . $pattern . ' has fewer than ' . $occurrence
                . ' match(es) in ' . self::FILES[$file] . '; update ' . __CLASS__ . '.'
            );
        }

        return substr_count($text, "\n", 0, (int) $found[0][$occurrence - 1][1]) + 1;
    }

    private static function driftMessage(string $file, string $pattern): string
    {
        return 'the ' . $file . ' anchor ' . $pattern;
    }

    private static function slice(string $text, string $startMarker, string $endMarker): string
    {
        $start = strpos($text, $startMarker);
        $end = strpos($text, $endMarker, $start === false ? 0 : $start);
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException(
                'ci-baseline-C9.md lost a section marker ("' . $startMarker . '" .. "'
                . $endMarker . '"); update ' . __CLASS__ . ' together with the doc.'
            );
        }

        return substr($text, $start, $end - $start);
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents($relative);
        if ($contents === false) {
            throw new RuntimeException($relative . ' moved or was deleted; update ' . __CLASS__ . '.');
        }

        return $contents;
    }
}

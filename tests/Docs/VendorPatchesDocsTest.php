<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Guard against the M-CONSOLE-51 class (review console__writer):
 * docs/vendor-patches.md once described itself as "this directory" capturing
 * hand-patched vendor files, promised patches "are applied as part of the CI
 * pipeline", cited a `patches/` placeholder directory that has never existed
 * in the repository's history, named the CI job `vendor-patches-check`
 * (the real job is `vendor-patches`), showed a stale grep whose pattern still
 * contained the bare `ORIGINAL` marker the workflow deliberately dropped
 * (09b4228 — packages legitimately ship COMPOSER_ORIGINAL_INIS), and listed a
 * `composer.json` `extra.patches` step inert without a patch plugin. Every
 * present-tense claim was checked against the filesystem and found wanting —
 * the prose contradicted both itself ("Do not hand-patch" a few lines below
 * "patches are applied as part of the CI pipeline") and the disk.
 *
 * Doctrine, per TerminalGraphicsDocsTest / CiBaselineClaimsDocsTest: real
 * state is DERIVED from the workflow, composer.json, .gitignore and the
 * filesystem at runtime, never restated from prose. Six laws:
 *
 * 1. The `vendor-patches` job name is derived from ci.yml; the doc must cite
 *    that exact name, and the fossil name `vendor-patches-check` may appear in
 *    no README/docs markdown file again.
 * 2. The detection step line is derived verbatim from ci.yml; the doc must
 *    quote that live line verbatim. A workflow marker change with stale prose
 *    reddens here (the prose side of the same law ci-baseline already pins for
 *    the CI-baseline doc). The derived alternation must also carry no bare
 *    `ORIGINAL` alternative, and the doc must keep telling readers that
 *    `ORIGINAL` is deliberately excluded.
 * 3. The absence of a `patches/` directory is derived from disk; the doc must
 *    say none exists. Should one ever land, this pin reddens until the doc,
 *    the workflow and this test move in one deliberate change (two-sided lock).
 * 4. The untracked-vendor posture is derived from .gitignore (`/vendor/`); the
 *    doc must state vendor/ is Composer-managed and untracked.
 * 5. The absence of patch-applier tooling is derived from composer.json (no
 *    `extra.patches`, no require key matching /patch/i); the doc must say so,
 *    and the fossil instruction "under `extra.patches`" may not return.
 * 6. The "CI applies nothing" law is pinned in both directions: the doc keeps
 *    its "applies nothing — it only detects and fails" wording, and the
 *    derived ci.yml step really contains no patch-applying command.
 *
 * Historical CHANGELOG entries are exempt forever (history is verbatim); the
 * sweep deliberately never reads it.
 */
final class VendorPatchesDocsTest extends TestCase
{
    private const DOC = 'docs/vendor-patches.md';

    private const WORKFLOW = '.github/workflows/ci.yml';

    /** Prose fossils the cured doc replaced; none may return anywhere in markdown. */
    private const FORBIDDEN_FOSSILS = [
        'vendor-patches-check',
        'applied as part of the CI pipeline',
        'exists as a placeholder',
        'This directory captures',
        'under `extra.patches`',
    ];

    public function testTheVendorPatchesJobIsDerivedFromTheWorkflowAndTheDocCitesIt(): void
    {
        $workflow = self::readRepoFile(self::WORKFLOW);
        self::assertMatchesRegularExpression(
            '/^  vendor-patches:$/m',
            $workflow,
            'the workflow no longer defines a top-level `vendor-patches` job — '
            . 'move docs/vendor-patches.md and this pin in the same change as the workflow.'
        );

        $doc = self::flat(self::readRepoFile(self::DOC));
        self::assertStringContainsString(
            '`vendor-patches` job',
            $doc,
            'docs/vendor-patches.md no longer names the CI job by its real derived name.'
        );
    }

    public function testTheDocQuotesTheLiveDetectionStepVerbatim(): void
    {
        $line = self::liveDetectionLine();
        $doc = self::flat(self::readRepoFile(self::DOC));

        self::assertStringContainsString(
            $line,
            $doc,
            'the vendor-patches detection step in ' . self::WORKFLOW . ' no longer matches the '
            . 'command quoted in docs/vendor-patches.md — move the prose and this pin with the workflow.'
        );
    }

    public function testTheDerivedMarkerSetCarriesNoBareOriginalAndTheDocTellsTheExclusion(): void
    {
        $alternatives = self::derivedMarkerAlternatives();

        self::assertNotContains(
            'ORIGINAL',
            $alternatives,
            'bare `ORIGINAL` is back in the CI marker pattern — the in-step comment '
            . '(packages ship COMPOSER_ORIGINAL_INIS / ORIGINAL_NODE) and both docs say it was '
            . 'dropped deliberately; re-admitting it needs a deliberate, documented reversal.'
        );

        $doc = self::flat(self::readRepoFile(self::DOC));
        self::assertStringContainsString(
            'bare `ORIGINAL` is deliberately **not** matched',
            $doc,
            'docs/vendor-patches.md lost the exclusion story — the marker list alone invites '
            . 'a future contributor to "fix" the grep by re-adding ORIGINAL.'
        );
    }

    public function testPatchesDirectoryAbsenceIsDerivedFromDiskAndMirroredInProse(): void
    {
        $exists = is_dir(dirname(__DIR__, 2) . '/patches');
        $doc = self::flat(self::readRepoFile(self::DOC));

        if ($exists) {
            self::fail(
                'a `patches/` directory has landed. docs/vendor-patches.md (and this pin) currently '
                . 'assert none exists; adopting a real patch pipeline means moving the doc, the '
                . 'workflow and this test in one deliberate change.'
            );
        }

        self::assertStringContainsString(
            'There is no `patches/` directory',
            $doc,
            'docs/vendor-patches.md no longer states the derived truth that no `patches/` directory exists.'
        );
    }

    public function testUntrackedVendorPostureIsDerivedFromGitignoreAndMirroredInProse(): void
    {
        $gitignore = self::readRepoFile('.gitignore');
        self::assertStringContainsString(
            '/vendor/',
            $gitignore,
            '.gitignore no longer ignores vendor/ — docs/vendor-patches.md builds its "nowhere to '
            . 'be committed" law on this; move prose and pin together.'
        );

        $doc = self::flat(self::readRepoFile(self::DOC));
        self::assertStringContainsString(
            '`vendor/` is Composer-managed and untracked',
            $doc,
            'docs/vendor-patches.md lost the untracked-vendor posture derived from .gitignore.'
        );
    }

    public function testComposerJsonPatchToolingAbsenceIsDerivedAndMirroredInProse(): void
    {
        $composer = json_decode(self::readRepoFile('composer.json'), true);
        if (!is_array($composer)) {
            throw new RuntimeException('composer.json did not parse');
        }

        self::assertArrayNotHasKey(
            'patches',
            $composer['extra'] ?? [],
            'composer.json grew an `extra.patches` key — if a patch plugin was wired up, '
            . 'docs/vendor-patches.md and this pin must be rewritten to the new reality together.'
        );

        foreach (['require', 'require-dev'] as $section) {
            foreach ((array) ($composer[$section] ?? []) as $package => $_) {
                self::assertDoesNotMatchRegularExpression(
                    '/patch/i',
                    (string) $package,
                    'a patch-applier package (' . $package . ') is now required — the doc and this '
                    . 'pin assert no patch tooling exists; move them in one deliberate change.'
                );
            }
        }

        $doc = self::flat(self::readRepoFile(self::DOC));
        self::assertStringContainsString(
            'there is no patch-applier tooling',
            $doc,
            'docs/vendor-patches.md no longer mirrors the derived "no patch tooling" composer.json state.'
        );
    }

    public function testTheAppliesNothingLawHoldsOnBothSides(): void
    {
        $workflow = self::readRepoFile(self::WORKFLOW);
        $job = self::vendorPatchesJobSlice($workflow);

        foreach (['codeload', 'patches apply', 'apply-patches', 'patch -p', 'git apply'] as $applyCommand) {
            self::assertStringNotContainsString(
                $applyCommand,
                $job,
                'the vendor-patches job now contains a patch-applying command (' . $applyCommand
                . ') — the docs law "it applies nothing" and its pin must move together with the workflow.'
            );
        }

        $doc = self::flat(self::readRepoFile(self::DOC));
        self::assertStringContainsString(
            'applies nothing',
            $doc,
            'docs/vendor-patches.md lost the "applies nothing" law while the derived job still applies nothing.'
        );
    }

    public function testNoMarkdownRepeatsTheProseFossils(): void
    {
        foreach (self::markdownFiles() as $path) {
            $flat = self::flat(self::readRepoFile($path));
            foreach (self::FORBIDDEN_FOSSILS as $fossil) {
                self::assertStringNotContainsString(
                    self::flat($fossil),
                    $flat,
                    $path . ' re-advertises the retired vendor-patches myth "' . $fossil . '".'
                );
            }
        }

        self::assertGreaterThanOrEqual(2, count(self::markdownFiles()));
    }

    /**
     * The detection-step line from ci.yml, leading YAML indentation trimmed.
     */
    private static function liveDetectionLine(): string
    {
        foreach (explode("\n", self::readRepoFile(self::WORKFLOW)) as $line) {
            if (preg_match('/PATCHED=\$\(grep -rEl/', $line) === 1) {
                return trim($line);
            }
        }

        throw new RuntimeException(
            'the vendor-patches detection step (PATCHED=$(grep -rEl ...) was not found in '
            . self::WORKFLOW . '; update this pin deliberately alongside docs/vendor-patches.md.'
        );
    }

    /**
     * The alternation markers, derived from the live detection line on disk.
     *
     * @return list<string>
     */
    private static function derivedMarkerAlternatives(): array
    {
        $line = self::liveDetectionLine();
        if (preg_match("/grep -rEl '\\((.*?)\\)' vendor\\//", $line, $m) !== 1) {
            throw new RuntimeException('could not derive the marker alternation from: ' . $line);
        }

        return explode('|', $m[1]);
    }

    /**
     * The vendor-patches job text, sliced from the workflow (job key to the next
     * top-level job key).
     */
    private static function vendorPatchesJobSlice(string $workflow): string
    {
        if (preg_match('/^  vendor-patches:$.*?(?=^  [a-z0-9-]+:$)/ms', $workflow, $m) !== 1) {
            throw new RuntimeException('could not slice the vendor-patches job out of ' . self::WORKFLOW);
        }

        return $m[0];
    }

    /**
     * README.md plus every markdown file under docs/ — CHANGELOG history is
     * exempt forever (house doctrine: history is verbatim).
     *
     * @return list<string> repo-relative paths
     */
    private static function markdownFiles(): array
    {
        $paths = ['README.md'];

        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::repoPath('docs')));
        $root = self::repoPath('docs') . '/';
        foreach ($walk as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'md') {
                $relative = substr($file->getPathname(), strlen($root));
                $paths[] = 'docs/' . str_replace('\\', '/', $relative);
            }
        }

        sort($paths);

        return $paths;
    }

    private static function repoPath(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        if (!file_exists($path)) {
            throw new RuntimeException('expected repo path missing: ' . $relative);
        }

        return $path;
    }

    private static function readRepoFile(string $relative): string
    {
        $contents = file_get_contents(self::repoPath($relative));
        if ($contents === false) {
            throw new RuntimeException('could not read ' . $relative);
        }

        return $contents;
    }

    /**
     * Markdown wraps mid-sentence, so every comparison happens on whitespace-
     * flattened text.
     */
    private static function flat(string $text): string
    {
        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }
}

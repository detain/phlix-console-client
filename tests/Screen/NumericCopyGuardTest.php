<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use PHPUnit\Framework\TestCase;

/**
 * Family-shape pin for numeric validation copy [review console__copywriter m6,
 * M-CONSOLE-13]. The numeric-bound messages carry load-bearing semantics
 * (>0 vs ≥0 vs unbounded — each matches its validator), so the wording must be
 * precise AND uniform: one template per bound, never drift.
 *
 * Derived from the tree (the precedent set by the docs-claim pins): every
 * string literal in src/Screen that talks about whole numbers must be one of
 * the canonical templates; the ≥ glyph stays out of user-facing strings
 * (LANG=C/legacy terminals — the review's glyph note, resolved in favor of
 * ASCII words); and numeric ranges in validation copy use the repo's en-dash
 * convention, never a stray ASCII hyphen (the 'Tag must be 1-100' outlier).
 */
final class NumericCopyGuardTest extends TestCase
{
    /** Every whole-number copy literal allowed in src/Screen, verbatim. */
    private const CANONICAL_TEMPLATES = [
        // >0 bound (isPositiveInt validators).
        'Enter a whole number greater than 0.',
        // ≥0 bound (isNonNegativeInt validators) — ASCII words, no ≥ glyph.
        'Enter a whole number of 0 or more.',
        // ≥0-with-blank bound (isNonNegativeIntOrBlank validators).
        'Enter a whole number of 0 or more, or leave blank.',
        // Unbounded int (schema-derived 'int' type labels).
        'Enter a whole number.',
        // Composite sentences that restate the bounds above.
        'Edit the automatic-backup schedule. Both values must be whole numbers greater than 0.',
        'Edit the series rule. Numeric fields take a whole number of 0 or more; leave a field blank to keep it.',
    ];

    /**
     * @return list<string> absolute paths of every src/Screen screen
     */
    private function screens(): array
    {
        $paths = glob((string) dirname(__DIR__, 2) . '/src/Screen/*.php');

        self::assertIsArray($paths);
        self::assertNotEmpty($paths, 'guard is vacuous if no screens exist');

        return $paths;
    }

    /**
     * Every T_CONSTANT_ENCAPSED_STRING literal in the given files.
     *
     * @param list<string> $paths
     * @return list<array{file: string, literal: string}>
     */
    private function literalsIn(array $paths): array
    {
        $literals = [];
        foreach ($paths as $path) {
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literals[] = ['file' => basename($path), 'literal' => $token[1]];
                }
            }
        }

        return $literals;
    }

    public function testWholeNumberCopyIsExactlyTheCanonicalTemplateFamily(): void
    {
        $offenders = [];
        foreach ($this->literalsIn($this->screens()) as $entry) {
            if (preg_match('/whole number/', $entry['literal']) !== 1) {
                continue;
            }

            $unquoted = trim($entry['literal'], "'\"");
            if (!in_array($unquoted, self::CANONICAL_TEMPLATES, true)) {
                $offenders[] = $entry['file'] . ': ' . $unquoted;
            }
        }

        self::assertSame([], $offenders, 'numeric validation wording drifted off the canonical templates [M-CONSOLE-13]');
    }

    public function testTheCanonicalTemplatesAreAllStillLiveInTheScreens(): void
    {
        $present = [];
        foreach ($this->literalsIn($this->screens()) as $entry) {
            $present[] = trim($entry['literal'], "'\"");
        }

        foreach (self::CANONICAL_TEMPLATES as $template) {
            self::assertContains($template, $present, 'a canonical numeric template vanished from src/Screen — retire it deliberately, not silently [M-CONSOLE-13]');
        }
    }

    public function testNoGreaterThanOrEqualGlyphInUserFacingScreenStrings(): void
    {
        $offenders = [];
        foreach ($this->literalsIn($this->screens()) as $entry) {
            if (str_contains($entry['literal'], '≥')) {
                $offenders[] = $entry['file'] . ': ' . $entry['literal'];
            }
        }

        self::assertSame([], $offenders, 'the ≥ glyph is out of user-facing strings (ASCII wording) [M-CONSOLE-13]');
    }

    public function testNumericRangesInValidationCopyUseTheEnDashConvention(): void
    {
        $offenders = [];
        foreach ($this->literalsIn($this->screens()) as $entry) {
            if (preg_match('/must be \d+-\d+/', $entry['literal']) === 1) {
                $offenders[] = $entry['file'] . ': ' . $entry['literal'];
            }
        }

        self::assertSame([], $offenders, "range copy mixes ASCII '-' with the repo's en-dash standard [M-CONSOLE-13]");
    }
}

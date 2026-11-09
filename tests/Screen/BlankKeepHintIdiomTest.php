<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use PHPUnit\Framework\TestCase;

/**
 * Blank-field hint idiom law [review console__writer #13, CU-037].
 *
 * Form fields whose blank value means "keep what is there" drifted across
 * three idioms: 'leave blank to keep', 'blank = unchanged', 'blank = host'.
 * The ratified form is the sentence stem 'leave blank to <keep the current X |
 * use the …>' — these screens render one field per full-width row, so the
 * compact parenthetical ('(blank = unchanged)' in a title) buys nothing and
 * only adds dialect. The compact 'blank = x' shape stays lawful ONLY inside
 * width-capped table placeholders, where a sentence cannot fit; none of the
 * scanned screens has such a field any more, and a title can never be one.
 *
 * Round 2 [review w13 L8 DD minors]: the scan legs used to read single-quoted
 * literals only — a planted double-quoted violation walked through every leg
 * green — and the canary duplicated the patterns instead of exercising them,
 * so it could not guard discovery. Both are fixed structurally: THE scanner
 * lives in exactly one pair of methods below, both quote shapes are read, and
 * every leg — the file walks and the self-conviction canary — runs through
 * those methods (estate tripwire law, w12 L1 family: a canary pins discovery
 * AND body, never a copy of it).
 */
final class BlankKeepHintIdiomTest extends TestCase
{
    /** Canonical sentence stem for blank-hint placeholders. */
    private const CANONICAL_PLACEHOLDER = '/^leave blank to (?:keep the current|use the) /';

    /** @return list<string> every screen source the law covers */
    private static function screenFiles(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/src/Screen/*.php');

        self::assertIsArray($files, 'screen sources are enumerable');
        self::assertNotEmpty($files, 'the scan has files to scan');

        return $files;
    }

    /**
     * THE placeholder scanner — the single body every leg shares. Reads both
     * quote shapes: /(['"])(…)\1/ pins the opener and closer together, so a
     * 'mixed" literal is simply not a string the scanner hallucinates.
     *
     * @return list<string> every 'blank' placeholder in $source that is not the stem
     */
    private static function placeholderOffenders(string $source): array
    {
        $count = preg_match_all('/->withPlaceholder\((["\'])(.*?)\1\)/', $source, $m);
        self::assertNotFalse($count, 'source is scannable');

        $offenders = [];
        foreach ($m[2] as $placeholder) {
            if (str_contains($placeholder, 'blank') && preg_match(self::CANONICAL_PLACEHOLDER, $placeholder) !== 1) {
                $offenders[] = $placeholder;
            }
        }

        return $offenders;
    }

    /**
     * THE title scanner — same shared-body law as the placeholder leg.
     *
     * @return list<string> every title in $source carrying a (blank …) parenthetical
     */
    private static function titleOffenders(string $source): array
    {
        $count = preg_match_all('/->withTitle\((["\'])(.*?)\1\)/', $source, $m);
        self::assertNotFalse($count, 'source is scannable');

        $offenders = [];
        foreach ($m[2] as $title) {
            if (str_contains($title, '(blank')) {
                $offenders[] = $title;
            }
        }

        return $offenders;
    }

    public function testEveryBlankHintPlaceholderIsTheSentenceStem(): void
    {
        $offenders = [];
        foreach (self::screenFiles() as $file) {
            foreach (self::placeholderOffenders((string) file_get_contents($file)) as $placeholder) {
                $offenders[] = basename($file) . ': ' . $placeholder;
            }
        }

        self::assertSame([], $offenders, 'blank hints must carry the sentence stem');
    }

    public function testNoCompactBlankProseLivesInFieldTitles(): void
    {
        $offenders = [];
        foreach (self::screenFiles() as $file) {
            foreach (self::titleOffenders((string) file_get_contents($file)) as $title) {
                $offenders[] = basename($file) . ': ' . $title;
            }
        }

        self::assertSame([], $offenders, 'titles carry no (blank = …) parenthetical');
    }

    public function testTheScannerLegConvictsItsOwnFixture(): void
    {
        // The canary runs THE SAME methods the file legs run above: narrowing
        // either pattern — back to single quotes only, or to one call shape —
        // reddens this leg because the double-quoted arms below stop
        // convicting. That is precisely the hole the review walked through
        // green, so it is planted here in both shapes and pinned by name.
        $fixture = <<<'PHP'
            ->withPlaceholder('leave blank to keep the current value')
            ->withPlaceholder('leave blank to use the server URL')
            ->withPlaceholder('leave blank to keep')
            ->withPlaceholder('blank = host')
            ->withPlaceholder("blank = host")
            ->withTitle('New password (blank = unchanged)')
            ->withTitle("Port (blank = keep)")
            ->withTitle('Label')
            PHP;

        self::assertSame(
            ['leave blank to keep', 'blank = host', 'blank = host'],
            self::placeholderOffenders($fixture),
            'the placeholder leg convicts every dialect arm in BOTH quote shapes and blesses both canonical arms',
        );
        self::assertSame(
            ['New password (blank = unchanged)', 'Port (blank = keep)'],
            self::titleOffenders($fixture),
            'the title leg convicts the parenthetical in both quote shapes and blesses the plain title',
        );
    }
}

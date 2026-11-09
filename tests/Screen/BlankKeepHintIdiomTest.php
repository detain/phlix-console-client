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
 * The scanner is tripwired against its own vacuity (the w12 L1 lesson): the
 * fixture leg plants a violation and pins the exact conviction.
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

    public function testEveryBlankHintPlaceholderIsTheSentenceStem(): void
    {
        $offenders = [];
        foreach (self::screenFiles() as $file) {
            $count = preg_match_all('/->withPlaceholder\(\'([^\']*)\'\)/', (string) file_get_contents($file), $m);
            self::assertNotFalse($count, 'screen source is readable');
            foreach ($m[1] as $placeholder) {
                if (str_contains($placeholder, 'blank') && preg_match(self::CANONICAL_PLACEHOLDER, $placeholder) !== 1) {
                    $offenders[] = basename($file) . ': ' . $placeholder;
                }
            }
        }

        self::assertSame([], $offenders, 'blank hints must carry the sentence stem');
    }

    public function testNoCompactBlankProseLivesInFieldTitles(): void
    {
        $offenders = [];
        foreach (self::screenFiles() as $file) {
            $count = preg_match_all('/->withTitle\(\'([^\']*)\'\)/', (string) file_get_contents($file), $m);
            self::assertNotFalse($count, 'screen source is readable');
            foreach ($m[1] as $title) {
                if (str_contains($title, '(blank')) {
                    $offenders[] = basename($file) . ': ' . $title;
                }
            }
        }

        self::assertSame([], $offenders, 'titles carry no (blank = …) parenthetical');
    }

    public function testTheScannerLegConvictsItsOwnFixture(): void
    {
        $fixture = <<<'PHP'
            ->withPlaceholder('leave blank to keep the current value')
            ->withPlaceholder('leave blank to use the server URL')
            ->withPlaceholder('leave blank to keep')
            ->withPlaceholder('blank = host')
            ->withTitle('New password (blank = unchanged)')
            ->withTitle('Label')
            PHP;

        $placeholderHits = [];
        preg_match_all('/->withPlaceholder\(\'([^\']*)\'\)/', $fixture, $pm);
        foreach ($pm[1] as $placeholder) {
            if (str_contains($placeholder, 'blank') && preg_match(self::CANONICAL_PLACEHOLDER, $placeholder) !== 1) {
                $placeholderHits[] = $placeholder;
            }
        }
        $titleHits = [];
        preg_match_all('/->withTitle\(\'([^\']*)\'\)/', $fixture, $tm);
        foreach ($tm[1] as $title) {
            if (str_contains($title, '(blank')) {
                $titleHits[] = $title;
            }
        }

        self::assertSame(
            ['leave blank to keep', 'blank = host'],
            $placeholderHits,
            'the placeholder leg convicts exactly the two dialect arms and blesses both canonical arms',
        );
        self::assertSame(
            ['New password (blank = unchanged)'],
            $titleHits,
            'the title leg convicts the parenthetical and blesses the plain title',
        );
    }
}

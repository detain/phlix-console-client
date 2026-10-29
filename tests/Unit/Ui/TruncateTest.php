<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Ui;

use FilesystemIterator;
use Phlix\Console\Ui\Truncate;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SugarCraft\Core\Util\Width;

/**
 * Pins for the M-CONSOLE-47 cure: the family of byte-identical private
 * truncate() helpers counted CODEPOINTS where the terminal bills CELLS, so
 * CJK titles overflowed their column ~2× and mb_substr split ZWJ emoji and
 * combining sequences. The repo now has ONE choke — Truncate::withEllipsis
 * over the vendor's grapheme + east-asian-width Width splitter.
 *
 * Red-on-parent law: every width table below replayed against the deleted
 * helper body (mb_strlen/mb_substr, codepoint budget) fails — 怪物… at 10
 * cells came back 19 columns wide, and the ZWJ/combining pins came back
 * severed mid-cluster.
 */
final class TruncateTest extends TestCase
{
    /** The review's measured case: 28 display cells, 14 codepoints. */
    private const CJK_TITLE = '怪物映画の非常に長いタイトル';

    /** 👨‍👩‍👦 — ONE cluster, 5 codepoints, 2 cells. */
    private const FAMILY_EMOJI = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

    /** e + COMBINING ACUTE — ONE cluster, 2 codepoints, 1 cell. */
    private const E_ACUTE = "e\u{0301}";

    public function testTheReviewCaseFitsCellsNotCodepoints(): void
    {
        self::assertSame(28, Width::string(self::CJK_TITLE), 'the fixture itself: 28 cells');
        self::assertSame(14, mb_strlen(self::CJK_TITLE, 'UTF-8'), 'what the old family counted');

        $fitted = Truncate::withEllipsis(self::CJK_TITLE, 10);

        self::assertSame('怪物映画…', $fitted, '9-cell budget takes four fullwidth chars, then the ellipsis');
        self::assertSame(9, Width::string($fitted));
    }

    public function testNeverSplitsAFamilyEmojiCluster(): void
    {
        self::assertSame(2, Width::string(self::FAMILY_EMOJI));
        self::assertSame(5, mb_strlen(self::FAMILY_EMOJI, 'UTF-8'));

        $text = self::FAMILY_EMOJI . ' ' . self::FAMILY_EMOJI . ' 怪物';
        $fitted = Truncate::withEllipsis($text, 3);

        self::assertSame(self::FAMILY_EMOJI . '…', $fitted, 'one whole emoji plus the ellipsis, nothing severed mid-ZWJ');
        self::assertStringNotContainsString('  ', $fitted);
        self::assertLessThanOrEqual(3, Width::string($fitted));
    }

    public function testNeverOrphansACombiningMark(): void
    {
        $text = self::E_ACUTE . self::E_ACUTE . self::E_ACUTE . 'tail';
        $fitted = Truncate::withEllipsis($text, 2);

        self::assertSame(self::E_ACUTE . '…', $fitted);
        self::assertLessThanOrEqual(2, Width::string($fitted));
        // The old codepoint cut at budget 1 would have emitted 'e' + ellipsis
        // and left the acute mark behind — here no result may END in a lone
        // combining mark at ANY budget.
        foreach (range(1, 10) as $cells) {
            $cut = Truncate::withEllipsis($text, $cells);
            self::assertNotSame("\u{0301}", mb_substr($cut, -1, 1, 'UTF-8'), "no trailing lone acute at {$cells} cells");
        }
    }

    public function testFitsBranchIsUntouchedTextWithoutEllipsis(): void
    {
        self::assertSame('short', Truncate::withEllipsis('short', 10));
        self::assertSame('怪物', Truncate::withEllipsis('怪物', 4), 'fits at exactly its width');
        self::assertSame('怪', Truncate::withEllipsis('怪', 2), 'fits at its width boundary');
        self::assertStringEndsNotWith('…', Truncate::withEllipsis('怪', 2));
    }

    public function testDegenerateBudgets(): void
    {
        self::assertSame('', Truncate::withEllipsis(self::CJK_TITLE, 0));
        self::assertSame('', Truncate::withEllipsis(self::CJK_TITLE, -3));
        self::assertSame('…', Truncate::withEllipsis(self::CJK_TITLE, 1), 'one cell is spent on the ellipsis');
    }

    public function testTheInvariantSweepOverAMixedCorpus(): void
    {
        $corpus = [
            'plain ascii title',
            self::CJK_TITLE,
            self::FAMILY_EMOJI . ' party ' . self::FAMILY_EMOJI,
            'a' . self::E_ACUTE . 'u' . "\u{0308}" . ' mixed accents',
            '🎬 怪物 ' . self::FAMILY_EMOJI . ' emoji mix',
            str_repeat('x', 200),
            '',
        ];

        foreach ($corpus as $text) {
            foreach (range(1, 40) as $cells) {
                $result = Truncate::withEllipsis($text, $cells);
                self::assertLessThanOrEqual(
                    $cells,
                    Width::string($result),
                    'the result never bills more cells than the budget: ' . json_encode($text) . " @ {$cells}"
                );
                if (Width::string($text) > $cells) {
                    self::assertTrue(str_ends_with($result, '…'), "a cut must show the ellipsis @ {$cells}");
                    self::assertSame(
                        rtrim(Width::truncate($text, $cells - 1)) . '…',
                        $result,
                        'the cut is exactly the fitted prefix plus one ellipsis'
                    );
                } else {
                    self::assertSame($text, $result, 'fits → untouched');
                }
            }
        }
    }

    public function testTheHandRolledTruncationFamilyIsExtinct(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));

        $chokeDefinitions = 0;
        $violations = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $chokeDefinitions += preg_match_all('/function withEllipsis\(/', $code);
            if (preg_match('/function truncate\(\s*string \$text, int \$maxWidth/', $code)) {
                $violations[] = $file->getPathname() . ' re-declares the deleted family helper';
            }
            if (preg_match("/mb_str(?:sub|imwidth)\\([^)]*\\)\\s*(?:\\)\\s*)?\\.\\s*'…'/", $code)) {
                $violations[] = $file->getPathname() . ' re-rolls a codepoint cut with an appended ellipsis';
            }
            if (preg_match('/\\$this->truncate\\(/', $code)) {
                $violations[] = $file->getPathname() . ' still calls the vanished private helper';
            }
        }

        self::assertSame([], $violations, 'M-CONSOLE-47 census: no src/ file may re-grow a truncation copy');
        self::assertSame(1, $chokeDefinitions, 'exactly one canonical withEllipsis definition in src/');
    }

    public function testTheSweptCallersRouteThroughTheChoke(): void
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $expect = [
            'Screen/AdminDuplicatesScreen.php' => 2,
            'Screen/AdminWatchHistoryScreen.php' => 1,
            'Screen/AdminWebhooksScreen.php' => 1,
            'I18n/ExceptionSentences.php' => 1,
        ];
        foreach ($expect as $rel => $calls) {
            $code = (string) file_get_contents($root . $rel);
            self::assertSame(
                $calls,
                preg_match_all('/Truncate::withEllipsis\\(/', $code),
                "{$rel} must route every truncation through the choke"
            );
        }
    }
}

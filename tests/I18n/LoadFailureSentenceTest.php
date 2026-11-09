<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\I18n;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The load-failure sentence family [review console__writer #7 / CU-032]:
 * 'Could not load the X.' had grown an article-less twin ('Could not load
 * playlists.') across sibling screens — literally the same nouns punctuated
 * two ways (AdminTranscodingScreen vs AdminSettingsScreen once differed only
 * by 'the'). Ratified template: 'Could not load the <thing>.'
 *
 * Two lawful neighbours are exempt because they are different, internally
 * uniform templates: 'Could not load more <thing>.' (pagination failures)
 * and 'Could not load this <thing>.' (detail-screen failures). A composed
 * prefix handed to ExceptionSentences::composed() ('Could not load rooms')
 * carries no terminal period and is outside the family by construction.
 *
 * The scan is tokenized (never grep-of-prose): every quoted literal ending
 * in 'Could not load ….' in src/ and the en.php catalog must follow the
 * template, so drift fails CI at the exact file:line that drifted.
 */
final class LoadFailureSentenceTest extends TestCase
{
    /** Literal bodies in the family (period-terminated) must start so. */
    private const FAMILY = '/^Could not load (.+)\.$/u';

    private const LAWFUL_PREFIXES = ['the ', 'more ', 'this '];

    public function testEveryLoadFailureSentenceFollowsTheTemplate(): void
    {
        $violations = [];
        foreach (self::scannedFiles() as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $inner = substr($token[1], 1, -1);
                if (preg_match(self::FAMILY, $inner, $m) !== 1) {
                    continue;
                }
                foreach (self::LAWFUL_PREFIXES as $prefix) {
                    if (str_starts_with($m[1], $prefix)) {
                        continue 2;
                    }
                }
                $violations[] = basename($file) . ':' . $token[2] . " '{$inner}'";
            }
        }

        $this->assertSame(
            [],
            $violations,
            'off-template load-failure sentences (want "Could not load the <thing>."): '
            . implode(', ', $violations),
        );
    }

    /** @return iterable<string> every src/ PHP file plus the en.php catalog */
    private static function scannedFiles(): iterable
    {
        $root = new RecursiveDirectoryIterator(
            __DIR__ . '/../../src',
            RecursiveDirectoryIterator::SKIP_DOTS,
        );
        foreach (new RecursiveIteratorIterator($root) as $info) {
            if ($info->isFile() && $info->getExtension() === 'php') {
                yield (string) $info->getPathname();
            }
        }

        yield __DIR__ . '/../../resources/lang/en.php';
    }
}

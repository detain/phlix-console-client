<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Screen\PlayerScreen;
use Phlix\Console\Screen\ServerScreen;
use Phlix\Console\Screen\ServersScreen;
use PHPUnit\Framework\TestCase;

/**
 * One sentence per outcome, defined exactly once in src/ [review
 * console__writer #15 / CU-039 (duplicated literals invite drift where a
 * shared const would not) + #10/#11/#16].
 *
 * The law is a literal census over src/ with a pinned count per sentence:
 * the canonical voice of each outcome appears exactly once (its const
 * definition) and every superseded phrasing appears exactly zero times.
 * A reintroduced copy — however well meant — reddens this gate rather
 * than silently becoming the third variant the user learns to distrust.
 *
 * The play-notice sentence keeps a deliberate second home in the en
 * catalog (`detail.play_notice`, presentation-prefixed); that pair is
 * pinned together by the triplet in tests/I18n/TranslatorTest.php.
 */
final class UserSentenceSingleSourceTest extends TestCase
{
    /**
     * sentence => expected count across src/ (1 = the single const home).
     *
     * @var array<string, int>
     */
    private const LAW = [
        // CU-035: the imperative validation voice; 'Please' retires.
        'Please enter a server URL.' => 0,
        'Enter a server URL.' => 1,
        // CU-039: the removal refusal had two raw copies (App + ServersScreen).
        'Cannot remove the active server.' => 1,
        // CU-036: one outcome, not two phrasings.
        'Could not prepare this title for playback.' => 1,
        'This title could not be prepared for playback.' => 0,
        // CU-039: the player's raw copy of the play-notice sentence.
        'This title has no playable source.' => 1,
        // CU-040: the house "Press k to X." voice; parenthetical style dead.
        'Press r to rescan.' => 1,
        '(r to rescan)' => 0,
        // CU-040: dev-voice download line reworded for users.
        'Unexpected response type' => 0,
        'The server response cannot be streamed.' => 1,
        // CU-041: the only user-facing 'colour' spelling retired.
        'Unknown widget colour' => 0,
    ];

    public function testEachSentenceHasExactlyOneHomeInSrc(): void
    {
        $sources = self::srcPhpFiles();
        self::assertGreaterThan(100, count($sources), 'the src/ census collapsed; scan mis-located');

        foreach (self::LAW as $sentence => $expected) {
            $actual = 0;
            $where = [];
            foreach ($sources as $path) {
                $count = substr_count((string) file_get_contents($path), $sentence);
                if ($count > 0) {
                    $actual += $count;
                    $where[] = basename($path) . ':' . $count;
                }
            }

            self::assertSame(
                $expected,
                $actual,
                "'" . $sentence . "' must appear {$expected}× in src/ (found {$actual}×: " . implode(', ', $where) . ')'
            );
        }
    }

    public function testTheSharedConstsCarryTheCanonicalSentences(): void
    {
        self::assertSame('Enter a server URL.', ServerScreen::EMPTY_URL_ERROR);
        self::assertSame('Cannot remove the active server.', ServersScreen::CANNOT_REMOVE_ACTIVE);
        self::assertSame('This title has no playable source.', PlayerScreen::NO_PLAYABLE_SOURCE);
    }

    /**
     * @return list<string> every PHP file under src/, absolute paths
     */
    private static function srcPhpFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];
        $stack = [$root];
        while ($stack !== []) {
            $dir = array_pop($stack);
            $entries = scandir((string) $dir);
            self::assertIsArray($entries, "unreadable directory {$dir}");
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path)) {
                    $stack[] = $path;
                } elseif (str_ends_with($entry, '.php')) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }
}

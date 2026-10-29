<?php

/**
 * Guard against the M-CONSOLE-21 class (review console__enduser): the watch-state
 * layer — `r` rate, `F` favorite, `w` watched, `l` thumbs up, `j` thumbs down on
 * the detail screen (src/Screen/DetailScreen.php:457,461,465,470,475), the home
 * watch-filter cycle `a` All → Watched → Unwatched → ♥ Favorites
 * (src/Screen/BrowseScreen.php:620-642) and the sidebar `H` history / `P`
 * playlists jumps (src/Screen/BrowseScreen.php:570-577) — is taught by the app's
 * own footers (resources/lang/en.php 'detail.hint', BrowseScreen SIDEBAR_HINT and
 * railsHint()) while the user manual documented none of it. The product's
 * self-documented help had outgrown the manual: a reader who learned the app from
 * docs never found the core daily loop.
 *
 * Doctrine, per PlayerKeyDocsTest / TokenFileNameDocsTest: the code side is
 * parsed from the token stream and from the real hint strings — never restated
 * from prose — and pinned against BOTH manual surfaces: the "#### Watching &
 * ratings" section of docs/clients/console.md and the "**Watch state**"
 * paragraph of README.md (its global Keys line already taught the sibling
 * detail keys `p`/`C`/`d`, so the same self-documenting help had outgrown it
 * too). Removing or renaming a shipped loop key reddens the matching
 * handler/footer test; dropping it from either surface reddens that surface's
 * census; a rewritten "where it surfaces" paragraph that loses a rail or the
 * filter cycle reddens the narrative test. Code, hints, docs and this pin move
 * in the same change or the suite stays red.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WatchStateLoopDocsTest extends TestCase
{
    /** Detail-screen key handler — home of r/F/w/l/j. */
    private const DETAIL_SOURCE = 'src/Screen/DetailScreen.php';

    /** Home screen handler — home of the `a` filter cycle and H/P sidebar jumps. */
    private const BROWSE_SOURCE = 'src/Screen/BrowseScreen.php';

    /** English catalog carrying the detail footer the keys must be taught in. */
    private const LANG_FILE = 'resources/lang/en.php';

    /** Section marker of the watch-state table in docs/clients/console.md. */
    private const DOC_SECTION = '#### Watching & ratings';

    /** Marker of the watch-state paragraph in README.md. */
    private const README_MARKER = '**Watch state**';

    /**
     * The detail-screen loop keys in footer case (first-appearance order).
     * `F` is spelled shift-case by 'detail.hint' itself; the handler arm
     * accepts both cases.
     *
     * @var list<string>
     */
    private const DETAIL_KEYS = ['r', 'F', 'w', 'l', 'j'];

    /**
     * The home-screen loop keys: rails `a` watch-filter cycle, sidebar
     * `H` history and `P` playlists.
     *
     * @var list<string>
     */
    private const HOME_KEYS = ['a', 'H', 'P'];

    /**
     * The full manual-table census: detail keys then home keys. A loop key
     * added to the handlers and hints (or retired from them) rotates this
     * list together with the docs/clients/console.md table.
     *
     * @var list<string>
     */
    private const DOCUMENTED_KEYS = ['r', 'F', 'w', 'l', 'j', 'a', 'H', 'P'];

    /**
     * Phrases the section must keep naming (case-sensitive, all required):
     * the filter cycle in order, and the home rails/screens the loop feeds.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_SECTION_PHRASES = [
        'filter cycle' => ['All → Watched → Unwatched → ♥ Favorites'],
        'result surfaces' => [
            'Continue Watching',
            'Up Next',
            'Most Watched',
            'watch history',
            'playlists',
        ],
    ];

    public function testTheDetailScreenHandlesEveryWatchStateKey(): void
    {
        $handled = array_map(
            static fn (string $key): string => strtolower($key),
            self::extractRuneLiterals(self::readRepoFile(self::DETAIL_SOURCE))
        );

        foreach (self::DETAIL_KEYS as $key) {
            self::assertContains(
                strtolower($key),
                $handled,
                self::DETAIL_SOURCE . ' no longer handles `' . $key . '` — the detail-screen '
                . 'watch-state loop, the footer hint and the "Watching & ratings" table in '
                . 'docs/clients/console.md must move together (M-CONSOLE-21).'
            );
        }
    }

    public function testTheDetailFooterHintTeachesEveryWatchStateKey(): void
    {
        $footer = self::singleLetterKeys(self::detailHintFooter());
        $missing = array_values(array_diff(self::DETAIL_KEYS, $footer));

        self::assertSame(
            [],
            $missing,
            "The 'detail.hint' footer in " . self::LANG_FILE . ' stopped teaching: '
            . implode(', ', $missing) . ' — the app self-documents in that hint; if the footer '
            . 'moved, mirror it in docs/clients/console.md and this pin, same change (M-CONSOLE-21).'
        );
    }

    public function testTheHomeScreenHandlesTheFilterCycleAndSidebarJumps(): void
    {
        $handled = array_map(
            static fn (string $key): string => strtolower($key),
            self::extractRuneLiterals(self::readRepoFile(self::BROWSE_SOURCE))
        );

        foreach (self::HOME_KEYS as $key) {
            self::assertContains(
                strtolower($key),
                $handled,
                self::BROWSE_SOURCE . ' no longer handles `' . $key . '` — the home watch-filter '
                . 'cycle / sidebar jumps, their hints and the "Watching & ratings" table in '
                . 'docs/clients/console.md must move together (M-CONSOLE-21).'
            );
        }
    }

    public function testTheHomeHintsTeachTheFilterCycleAndSidebarJumps(): void
    {
        $railsHint = self::railsHintLiteral();
        self::assertStringContainsString(
            'a  filter(',
            $railsHint,
            'The rails hint in ' . self::BROWSE_SOURCE . ' no longer teaches `a  filter(…)` — '
            . 'the product teaches the key in this hint, the manual mirrors it (M-CONSOLE-21).'
        );

        $sidebarHint = self::sidebarHintLiteral();
        foreach (['H  history', 'P  playlists'] as $taught) {
            self::assertStringContainsString(
                $taught,
                $sidebarHint,
                'SIDEBAR_HINT in ' . self::BROWSE_SOURCE . ' no longer teaches `' . $taught
                . '` — move the hint, the manual table and this pin together (M-CONSOLE-21).'
            );
        }
    }

    public function testTheManualTeachesTheWatchStateLoopKeys(): void
    {
        $section = self::manualSection();
        $listed = self::tableFirstColumnKeys($section);
        $missing = array_values(array_diff(self::DOCUMENTED_KEYS, $listed));

        self::assertSame(
            [],
            $missing,
            'The "Watching & ratings" table in docs/clients/console.md is missing loop keys the '
            . 'handlers and footers ship: ' . implode(', ', $missing) . ' — document every shipped '
            . 'state key (M-CONSOLE-21: the manual must teach the core daily loop).'
        );
    }

    public function testTheManualTableCensusMatchesThePinnedList(): void
    {
        self::assertSame(
            self::DOCUMENTED_KEYS,
            self::tableFirstColumnKeys(self::manualSection()),
            'The "Watching & ratings" table in docs/clients/console.md drifted from the pinned '
            . 'census: a key was added, removed or reordered. Move the handlers, the hints, the '
            . 'table and DOCUMENTED_KEYS here together (M-CONSOLE-21).'
        );
    }

    public function testTheReadmeTeachesTheWatchStateLoop(): void
    {
        $doc = self::readRepoFile('README.md');
        $start = strpos($doc, self::README_MARKER);
        if ($start === false) {
            throw new RuntimeException(
                'README.md no longer carries "' . self::README_MARKER . '" — the global Keys line '
                . 'teaches the sibling detail keys, so it must teach the watch-state loop too '
                . '(M-CONSOLE-21); restore the paragraph or update ' . self::class . ' together.'
            );
        }

        $rest = substr($doc, $start + strlen(self::README_MARKER));
        $end = strpos($rest, "\n\n");
        $paragraph = $end === false ? $rest : substr($rest, 0, $end);

        $listed = self::backtickLetters($paragraph);
        $missing = array_values(array_diff(self::DOCUMENTED_KEYS, $listed));

        self::assertSame(
            [],
            $missing,
            'The "**Watch state**" paragraph in README.md is missing loop keys the handlers and '
            . 'footers ship: ' . implode(', ', $missing) . ' (M-CONSOLE-21).'
        );

        self::assertStringContainsString(
            'All → Watched → Unwatched → ♥ Favorites',
            $paragraph,
            'README.md no longer states the home filter cycle in the pinned form — move the '
            . 'paragraph, the manual section and this pin together (M-CONSOLE-21).'
        );
    }

    public function testTheManualSectionNamesTheFilterCycleAndResultSurfaces(): void
    {
        $section = self::manualSection();

        foreach (self::REQUIRED_SECTION_PHRASES as $group => $phrases) {
            $missing = array_values(array_filter(
                $phrases,
                static fn (string $phrase): bool => !str_contains($section, $phrase)
            ));

            self::assertSame(
                [],
                $missing,
                'The "Watching & ratings" section no longer names the ' . $group . ' (missing: '
                . implode(', ', $missing) . ') — teaching the keys without where the state '
                . 'surfaces leaves the daily loop half-taught (M-CONSOLE-21).'
            );
        }
    }

    // ---- extraction ----------------------------------------------------

    /**
     * Every single-letter-string literal compared as `$x->rune === 'lit'`,
     * in first-appearance order, parsed from the token stream so prose can
     * never drift from the handlers silently again (house pattern from
     * PlayerKeyDocsTest).
     *
     * @return list<string>
     */
    private static function extractRuneLiterals(string $source): array
    {
        $tokens = token_get_all($source);
        $total = count($tokens);
        $keys = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'rune') {
                continue;
            }

            $literalPos = $i;
            $literal = self::literalAfterRune($tokens, $i, $literalPos);
            if ($literal === null) {
                continue;
            }

            $literals = [$literal];
            while (true) {
                $nextRune = self::nextRuneInChain($tokens, $literalPos);
                if ($nextRune === null) {
                    break;
                }
                $chainPos = $nextRune;
                $chainLiteral = self::literalAfterRune($tokens, $nextRune, $chainPos);
                if ($chainLiteral === null) {
                    break;
                }
                $literals[] = $chainLiteral;
                $literalPos = $chainPos;
            }

            foreach ($literals as $armLiteral) {
                if (
                    strlen($armLiteral) === 1 && ctype_alpha($armLiteral)
                    && !in_array($armLiteral, $keys, true)
                ) {
                    $keys[] = $armLiteral;
                }
            }

            $i = $literalPos;
        }

        return $keys;
    }

    /**
     * The literal of one `` $x->rune === 'lit' `` comparison whose `rune`
     * token sits at $runePos; $literalPos comes back with the literal token
     * index (unchanged when null is returned).
     *
     * @param list<string|int|array{int,string,int}> $tokens
     */
    private static function literalAfterRune(array $tokens, int $runePos, ?int &$literalPos): ?string
    {
        $eq = self::skipWhitespace($tokens, $runePos);
        if ($eq === null || !is_array($tokens[$eq]) || $tokens[$eq][0] !== T_IS_IDENTICAL) {
            return null;
        }
        $lit = self::skipWhitespace($tokens, $eq);
        if ($lit === null || !is_array($tokens[$lit]) || $tokens[$lit][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $literalPos = $lit;

        return trim((string) $tokens[$lit][1], "'\"");
    }

    /**
     * Index of the `rune` token of the next `` || $x->rune === `` link in the
     * chain starting after $literalPos, or null when the chain ends.
     *
     * @param list<string|int|array{int,string,int}> $tokens
     */
    private static function nextRuneInChain(array $tokens, int $literalPos): ?int
    {
        $or = self::skipWhitespace($tokens, $literalPos);
        if ($or === null || !is_array($tokens[$or]) || $tokens[$or][0] !== T_BOOLEAN_OR) {
            return null;
        }
        $var = self::skipWhitespace($tokens, $or);
        if ($var === null || !is_array($tokens[$var]) || $tokens[$var][0] !== T_VARIABLE) {
            return null;
        }
        $arrow = self::skipWhitespace($tokens, $var);
        if ($arrow === null || !is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_OBJECT_OPERATOR) {
            return null;
        }
        $rune = self::skipWhitespace($tokens, $arrow);
        if (
            $rune === null
            || !is_array($tokens[$rune])
            || $tokens[$rune][0] !== T_STRING
            || $tokens[$rune][1] !== 'rune'
        ) {
            return null;
        }

        return $rune;
    }

    /**
     * Index of the next non-whitespace token after $from, or null at EOF.
     *
     * @param list<string|int|array{int,string,int}> $tokens
     */
    private static function skipWhitespace(array $tokens, int $from): ?int
    {
        $total = count($tokens);
        for ($p = $from + 1; $p < $total; $p++) {
            if (!is_array($tokens[$p]) || $tokens[$p][0] !== T_WHITESPACE) {
                return $p;
            }
        }

        return null;
    }

    /** The 'detail.hint' footer string from the real English catalog. */
    private static function detailHintFooter(): string
    {
        $catalog = require self::repoPath(self::LANG_FILE);
        if (!is_array($catalog) || !isset($catalog['detail.hint']) || !is_string($catalog['detail.hint'])) {
            throw new RuntimeException(
                self::LANG_FILE . ' no longer returns a string detail.hint — the detail-screen '
                . 'footer is the teaching surface for the watch-state keys (M-CONSOLE-21).'
            );
        }

        return $catalog['detail.hint'];
    }

    /**
     * Single alphabetic characters appearing as standalone whitespace-
     * delimited tokens — the key column of a rendered footer hint.
     *
     * @return list<string>
     */
    private static function singleLetterKeys(string $hint): array
    {
        preg_match_all('/(?<=^|\s)([A-Za-z])(?=\s)/', $hint, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** The railsHint() return literal from BrowseScreen.php source. */
    private static function railsHintLiteral(): string
    {
        $source = self::readRepoFile(self::BROWSE_SOURCE);
        if (
            preg_match(
                '/private function railsHint\(\): string\s*\{.*?return "([^"]*)";/s',
                $source,
                $match
            ) !== 1
        ) {
            throw new RuntimeException(
                self::BROWSE_SOURCE . ' no longer returns a plain double-quoted literal from '
                . 'railsHint() — if the rails hint changed shape, update ' . self::class . ' in the '
                . 'same change (M-CONSOLE-21).'
            );
        }

        return $match[1];
    }

    /** The SIDEBAR_HINT constant literal from BrowseScreen.php source. */
    private static function sidebarHintLiteral(): string
    {
        $source = self::readRepoFile(self::BROWSE_SOURCE);
        if (
            preg_match("/const SIDEBAR_HINT = '([^']*)';/", $source, $match) !== 1
        ) {
            throw new RuntimeException(
                self::BROWSE_SOURCE . ' no longer declares SIDEBAR_HINT as a plain literal — if '
                . 'the sidebar hint changed shape, update ' . self::class . ' in the same change '
                . '(M-CONSOLE-21).'
            );
        }

        return $match[1];
    }

    /**
     * The "#### Watching & ratings" section of docs/clients/console.md, up
     * to the next heading — fails loudly when the section is absent, which
     * is exactly the reddening this guard exists for.
     */
    private static function manualSection(): string
    {
        $doc = self::readRepoFile('docs/clients/console.md');
        $start = strpos($doc, self::DOC_SECTION);
        if ($start === false) {
            throw new RuntimeException(
                'docs/clients/console.md no longer has a "' . self::DOC_SECTION . '" section — '
                . 'the manual must teach the watch-state core daily loop (M-CONSOLE-21); '
                . 'restore it or update ' . self::class . ' in the same change.'
            );
        }

        $rest = substr($doc, $start + strlen(self::DOC_SECTION));
        $break = preg_match('/^#{3,5} /m', $rest, $hit, PREG_OFFSET_CAPTURE);

        return $break === 1 ? substr($rest, 0, $hit[0][1]) : $rest;
    }

    /**
     * Single letters wrapped in backticks in the first column of the
     * section's markdown table, in row order.
     *
     * @return list<string>
     */
    private static function tableFirstColumnKeys(string $section): array
    {
        $keys = [];
        foreach (explode("\n", $section) as $line) {
            if (!str_starts_with($line, '|')) {
                continue;
            }
            $columnOne = explode('|', $line)[1];
            preg_match_all('/`([^`]+)`/', $columnOne, $matches);
            foreach ($matches[1] as $span) {
                if (strlen($span) === 1 && ctype_alpha($span) && !in_array($span, $keys, true)) {
                    $keys[] = $span;
                }
            }
        }

        return $keys;
    }

    /**
     * Single alphabetic characters wrapped in backticks, in order of first
     * appearance (house helper from PlayerKeyDocsTest).
     *
     * @return list<string>
     */
    private static function backtickLetters(string $text): array
    {
        preg_match_all('/`([^`]+)`/', $text, $matches);
        $keys = [];
        foreach ($matches[1] as $span) {
            if (strlen($span) === 1 && ctype_alpha($span) && !in_array($span, $keys, true)) {
                $keys[] = $span;
            }
        }

        return $keys;
    }

    private static function repoPath(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException($relative . ' moved or was deleted; update ' . self::class . '.');
        }

        return $path;
    }

    private static function readRepoFile(string $relative): string
    {
        $contents = file_get_contents(self::repoPath($relative));
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read ' . $relative . '.');
        }

        return $contents;
    }
}

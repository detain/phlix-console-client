<?php

/**
 * Guard against the M-CONSOLE-02 class (review console__a11y): six shipped
 * player keys — `a`/`A` audio-track picker, `S` subtitle-track picker,
 * `C` chapter picker, `w`/`W` "For You" recommendations, `y`/`Y` SyncPlay
 * modal, `z`/`Z` sleep timer — lived in src/Screen/PlayerScreen.php's key
 * handler while appearing in NO documentation key table.
 *
 * The real key list is parsed from the code (`token_get_all`, never
 * restated from prose) and pinned against both docs that carry a player
 * key table: the "Media playback" table in docs/clients/console.md and the
 * "**In the player:**" line in README.md. A new handler arm without docs
 * reddens the suite; a renamed/removed arm reddens the census pin and its
 * message directs code and docs to move together — the same doctrine as
 * TokenFileNameDocsTest for the token-file name.
 *
 * Case law encoded here: an arm listing BOTH cases of a letter is
 * case-insensitive and docs write it lowercase (siblings `f`, `v`, `n`);
 * an arm listing ONLY the shift case is a distinct action and docs must
 * show it uppercase (sibling: detail-screen `C` cast). That is why `S` and
 * `C` are uppercase pins: unshifted `s` skips intro/outro and `c` toggles
 * captions, and each picker consumes its shift rune before those arms.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlayerKeyDocsTest extends TestCase
{
    /** Source of truth: the player screen's key handler. */
    private const PLAYER_SOURCE = 'src/Screen/PlayerScreen.php';

    /** Section marker of the player table in docs/clients/console.md. */
    private const CONSOLE_SECTION = '#### Media playback';

    /** Section marker of the player line in README.md. */
    private const README_MARKER = '**In the player:**';

    /**
     * The full census of letters the player handles, in documented form
     * (first-appearance order). A new or removed rune arm reddens this
     * pin: document the key in BOTH docs and rotate the list in the same
     * change. The six missing from every table (a, S, C, w, y, z) are
     * exactly what review console__a11y found (M-CONSOLE-02).
     */
    private const DOCUMENTED_KEYS = ['q', 'f', 'v', 'a', 'S', 'C', 's', 'o', 'c', 'n', 'p', 'w', 'y', 'z'];

    public function testTheExtractedPlayerKeyCensusMatchesThePinnedList(): void
    {
        self::assertSame(
            self::DOCUMENTED_KEYS,
            self::extractHandledKeys(),
            self::PLAYER_SOURCE . ' key handling drifted from the census: add or remove the row in README.md '
            . 'AND docs/clients/console.md, then rotate DOCUMENTED_KEYS here, in the same change.'
        );
    }

    public function testEveryHandledPlayerKeyIsListedInTheConsoleDocTable(): void
    {
        $listed = self::mediaPlaybackTableKeys();
        $missing = array_values(array_diff(self::extractHandledKeys(), $listed));

        self::assertSame(
            [],
            $missing,
            'The "Media playback" table in docs/clients/console.md is missing player keys handled by '
            . self::PLAYER_SOURCE . ': ' . implode(', ', $missing)
            . ' — every shipped key must be in the table (M-CONSOLE-02).'
        );
    }

    public function testEveryHandledPlayerKeyIsListedInTheReadmePlayerLine(): void
    {
        $listed = self::readmePlayerLineKeys();
        $missing = array_values(array_diff(self::extractHandledKeys(), $listed));

        self::assertSame(
            [],
            $missing,
            'The "**In the player:**" line in README.md is missing player keys handled by '
            . self::PLAYER_SOURCE . ': ' . implode(', ', $missing)
            . ' — every shipped key must be in the line (M-CONSOLE-02).'
        );
    }

    /**
     * Every single-letter `$msg->rune` key the player compares, in
     * first-appearance order and documented case form, parsed from the
     * token stream so prose and code can never drift apart silently again.
     *
     * @return list<string>
     */
    private static function extractHandledKeys(): array
    {
        $tokens = token_get_all(self::readRepoFile(self::PLAYER_SOURCE));
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

            $key = self::documentedForm($literals);
            if ($key !== null && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }

            $i = $literalPos;
        }

        return $keys;
    }

    /**
     * The form docs must use for one arm's literals: a lowercase member
     * wins (the arm is case-insensitive, docs write it lowercase like the
     * `f`/`v`/`n` siblings); otherwise the lone shift-case stands (docs
     * must write it uppercase, like `S` and `C`).
     *
     * @param list<string> $literals
     */
    private static function documentedForm(array $literals): ?string
    {
        $lower = null;
        $upper = null;
        foreach ($literals as $literal) {
            if (strlen($literal) !== 1 || !ctype_alpha($literal)) {
                continue;
            }
            if (ctype_lower($literal)) {
                $lower ??= $literal;
            } else {
                $upper ??= $literal;
            }
        }

        return $lower ?? $upper;
    }

    /**
     * The literal of a `` $x->rune === 'lit' `` comparison whose `rune`
     * token sits at $runePos; $literalPos comes back with the index of the
     * literal token (unchanged when null is returned).
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
     * Index of the `rune` token of the next `` || $x->rune === `` link in
     * the chain starting after the literal at $literalPos; null when the
     * chain ends. Note `` || `` tokenises as T_BOOLEAN_OR (T_LOGICAL_OR is
     * the `or` keyword).
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
     * Column-1 keys of the "Media playback" table in
     * docs/clients/console.md (single letters in backticks).
     *
     * @return list<string>
     */
    private static function mediaPlaybackTableKeys(): array
    {
        $doc = self::readRepoFile('docs/clients/console.md');
        $start = strpos($doc, self::CONSOLE_SECTION);
        if ($start === false) {
            throw new RuntimeException(
                'docs/clients/console.md no longer has a "' . self::CONSOLE_SECTION . '" section; '
                . 'relocate the player key table and update ' . self::class . ' in the same change.'
            );
        }

        $rest = substr($doc, $start + strlen(self::CONSOLE_SECTION));
        $break = preg_match('/^#{3,5} /m', $rest, $hit, PREG_OFFSET_CAPTURE);
        $section = $break === 1 ? substr($rest, 0, $hit[0][1]) : $rest;

        $keys = [];
        foreach (explode("\n", $section) as $line) {
            if (!str_starts_with($line, '|')) {
                continue;
            }
            $columnOne = explode('|', $line)[1];
            foreach (self::backtickLetters($columnOne) as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Letters used on the "**In the player:**" line of README.md.
     *
     * @return list<string>
     */
    private static function readmePlayerLineKeys(): array
    {
        $doc = self::readRepoFile('README.md');
        $start = strpos($doc, self::README_MARKER);
        if ($start === false) {
            throw new RuntimeException(
                'README.md no longer carries "' . self::README_MARKER . '"; relocate the player key '
                . 'line and update ' . self::class . ' in the same change.'
            );
        }

        $rest = substr($doc, $start + strlen(self::README_MARKER));
        $end = strpos($rest, "\n\n");
        $line = $end === false ? $rest : substr($rest, 0, $end);

        return self::backtickLetters($line);
    }

    /**
     * Single alphabetic characters wrapped in backticks, in order.
     *
     * @return list<string>
     */
    private static function backtickLetters(string $text): array
    {
        preg_match_all('/`([^`]+)`/', $text, $matches);

        return array_values(array_filter(
            $matches[1],
            static fn (string $span): bool => strlen($span) === 1 && ctype_alpha($span)
        ));
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

    private static function readRepoFile(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException($relative . ' moved or was deleted; update ' . self::class . '.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read ' . $relative . '.');
        }

        return $contents;
    }
}

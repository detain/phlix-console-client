<?php

/**
 * Guard against the M-CONSOLE-40 class (review console__devops): bin/phlix's
 * diagnostics (the `doctor` server-reachability probe and the `run --selftest`
 * http-reachable ladder) called curl_init() and friends on a PHP without
 * ext-curl — and ext-curl is deliberately NOT a composer require (the
 * RuntimeRequirementsDocsTest constitution keeps it a docs-only diagnostics
 * extension), so those sites FATALED with "undefined function" instead of
 * reporting an honest FAIL line.
 *
 * Doctrine, per PlayerKeyDocsTest/ScreenInventoryDocsTest: the census is
 * DERIVED from the code (token_get_all over bin/phlix), never restated from
 * prose. Every `curl_*` call site must sit in the protected arm of an
 * ext-curl capability check — either inside the else/then block of an
 * `if (function_exists('curl_init'))` test, or after a negated
 * `if (!function_exists('curl_init')) { return/exit … }` early bail in the
 * same enclosing block (the mintRelayToken shape, bin/phlix). A new curl
 * site without a guard reddens this suite; guards must name curl_init so the
 * capability test covers the whole extension (curl_setopt/curl_exec live and
 * die together with curl_init).
 *
 * NEVER execute the probe paths here: the venue note in BinPhlixTest records
 * that host PHP 8.3.x + curl 8.5 segfaults at shutdown after any curl_exec,
 * so this test is structural (token walk) and launches nothing.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DiagnosticsCurlGuardTest extends TestCase
{
    /** Source of truth: the CLI entry point. */
    private const BIN_SOURCE = 'bin/phlix';

    /** Exact count of ext-curl capability guards the file must carry. */
    private const EXPECTED_GUARD_COUNT = 3;

    public function testTheBinCarriesCurlCallSitesAndTheirGuards(): void
    {
        $sites = self::curlCallSites();
        $guards = self::capabilityGuards();

        // Census non-empty: if the walk ever silently parses nothing, this
        // demands a deliberate update rather than a green-by-vacuity.
        self::assertNotEmpty($sites, self::BIN_SOURCE . ': no curl_* call sites parsed — the walk broke or the file moved shape.');
        self::assertCount(
            self::EXPECTED_GUARD_COUNT,
            $guards,
            self::BIN_SOURCE . ': ext-curl capability guards drifted from the census '
            . '(mintRelayToken + doctor probe + selftest ladder). A new diagnostics '
            . 'curl site must arrive with its own guard, added in the same change.'
        );
    }

    public function testEveryCurlCallSiteSitsInsideACapabilityGuard(): void
    {
        $unguarded = [];
        foreach (self::curlCallSites() as $site) {
            $protected = false;
            foreach (self::capabilityGuards() as $guard) {
                if (self::guardProtects($guard, $site)) {
                    $protected = true;
                    break;
                }
            }
            if (!$protected) {
                $unguarded[] = $site['name'] . ' at line ' . $site['line'];
            }
        }

        self::assertSame(
            [],
            $unguarded,
            self::BIN_SOURCE . ' calls ext-curl without a function_exists guard arm '
            . '(review M-CONSOLE-40): ' . implode('; ', $unguarded)
        );
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @return list<array{name: string, line: int, index: int, depth: int}>
     */
    private static function curlCallSites(): array
    {
        $tokens = self::binTokens();
        $depths = self::braceDepths($tokens);
        $sites = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            if (!preg_match('/^curl_[a-z_]+$/', $token[1])) {
                continue;
            }
            // Call position: next non-whitespace token is '('.
            $j = $i + 1;
            while (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (($tokens[$j] ?? null) !== '(') {
                continue; // e.g. a bare reference inside a guard's string.
            }
            $sites[] = ['name' => $token[1], 'line' => $token[2], 'index' => $i, 'depth' => $depths[$i]];
        }

        return $sites;
    }

    /**
     * @return list<array{ifIndex: int, thenOpen: int, thenClose: int, elseOpen: int|null, elseClose: int|null, negated: bool, hasBail: bool, depth: int, line: int}>
     */
    private static function capabilityGuards(): array
    {
        $tokens = self::binTokens();
        $depths = self::braceDepths($tokens);
        $guards = [];
        $total = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'function_exists') {
                continue;
            }
            // The capability string must be 'curl_init' within the next few tokens.
            if (!self::nextMeaningfulIs($tokens, $i, '(')) {
                continue;
            }
            $arg = self::meaningfulAt($tokens, self::meaningfulOffset($tokens, $i, 2));
            if (!is_array($arg) || $arg[0] !== T_CONSTANT_ENCAPSED_STRING || !str_contains($arg[1], 'curl_init')) {
                continue;
            }

            $negated = self::precededByBang($tokens, $i);
            [$ifIndex, $block] = self::locateIfBlock($tokens, $i);
            if ($block === null) {
                throw new RuntimeException('function_exists curl guard at line ' . $token[2] . ' has no block — unexpected shape.');
            }
            [$thenOpen, $thenClose, $elseOpen, $elseClose] = $block;

            $guards[] = [
                'ifIndex' => $ifIndex,
                'thenOpen' => $thenOpen,
                'thenClose' => $thenClose,
                'elseOpen' => $elseOpen,
                'elseClose' => $elseClose,
                'negated' => $negated,
                'hasBail' => self::blockContainsBail($tokens, $thenOpen, $thenClose, $negated),
                'depth' => $depths[$ifIndex],
                'line' => $token[2],
            ];
        }

        return $guards;
    }

    /**
     * @param array<string, mixed> $guard
     * @param array{name: string, line: int, index: int, depth: int} $site
     */
    private static function guardProtects(array $guard, array $site): bool
    {
        $i = $site['index'];

        // Shape A — call inside the guard's protected branch.
        if ($guard['negated'] && $guard['elseOpen'] !== null && $guard['elseClose'] !== null) {
            if ($i > $guard['elseOpen'] && $i < $guard['elseClose']) {
                return true; // if (!has) { fail } else { CALL }
            }
        }
        if (!$guard['negated'] && $i > $guard['thenOpen'] && $i < $guard['thenClose']) {
            return true; // if (has) { CALL }
        }

        // Shape B — early bail: negated guard with return/exit, and the call
        // sits after the guard block in the SAME enclosing block.
        if ($guard['negated'] && $guard['hasBail']) {
            $afterGuard = $guard['elseClose'] ?? $guard['thenClose'];
            if ($i > $afterGuard && $site['depth'] === $guard['depth']) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: int, 1: array{0: int, 1: int, 2: int|null, 3: int|null}|null} [ifIndex, block] */
    private static function locateIfBlock(array $tokens, int $fnIndex): array
    {
        // Walk back to the T_IF opening this condition.
        $ifIndex = $fnIndex;
        while ($ifIndex >= 0 && (!is_array($tokens[$ifIndex]) || $tokens[$ifIndex][0] !== T_IF)) {
            $ifIndex--;
        }
        if ($ifIndex < 0) {
            throw new RuntimeException('function_exists() outside an if — unexpected guard shape.');
        }

        $open = self::findMatching($tokens, $ifIndex, ')');
        if ($open === null) {
            return [$ifIndex, null];
        }
        $thenOpen = self::nextOpenBrace($tokens, $open);
        if ($thenOpen === null) {
            return [$ifIndex, null];
        }
        $thenClose = self::findMatching($tokens, $thenOpen, '}');
        if ($thenClose === null) {
            return [$ifIndex, null];
        }

        $elseOpen = null;
        $elseClose = null;
        $after = self::skipWhitespace($tokens, $thenClose + 1);
        if (is_array($tokens[$after] ?? null) && $tokens[$after][0] === T_ELSE) {
            $elseBrace = self::nextOpenBrace($tokens, $after);
            if ($elseBrace !== null) {
                $elseOpen = $elseBrace;
                $elseClose = self::findMatching($tokens, $elseBrace, '}');
            }
        }

        return [$ifIndex, [$thenOpen, $thenClose, $elseOpen, $elseClose]];
    }

    /** @param list<mixed> $tokens */
    private static function blockContainsBail(array $tokens, int $open, int $close, bool $negated): bool
    {
        if (!$negated) {
            return false;
        }
        for ($i = $open + 1; $i < $close; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_RETURN, T_EXIT, T_CONTINUE], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $tokens */
    private static function precededByBang(array $tokens, int $fnIndex): bool
    {
        // `if (! function_exists(...))` — the '!' arrives as a plain-text
        // token (PHP 8 has no T_OPEN_PARENTHESIS/T_BANG token classes).
        $prev = self::skipWhitespaceBack($tokens, $fnIndex - 1);

        return ($tokens[$prev] ?? null) === '!';
    }

    /** @return list<array{0: mixed, 1: string, 2: int}|string> */
    private static function binTokens(): array
    {
        $path = self::repoRoot() . '/' . self::BIN_SOURCE;
        $code = file_get_contents($path);
        if ($code === false) {
            throw new RuntimeException('cannot read ' . $path);
        }

        return token_get_all($code);
    }

    /**
     * Brace depth per token index (depth of the token itself).
     *
     * @param list<mixed> $tokens
     * @return array<int, int>
     */
    private static function braceDepths(array $tokens): array
    {
        $depth = 0;
        $depths = [];
        foreach ($tokens as $i => $token) {
            $text = is_array($token) ? $token[0] : $token;
            $isBraceOpen = $text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
            $isBraceClose = $text === '}';
            if ($isBraceClose) {
                $depth--;
            }
            $depths[$i] = $depth;
            if ($isBraceOpen) {
                $depth++;
            }
        }

        return $depths;
    }

    /** @param list<mixed> $tokens */
    private static function nextMeaningfulIs(array $tokens, int $from, string $char): bool
    {
        $j = self::skipWhitespace($tokens, $from + 1);

        return ($tokens[$j] ?? null) === $char;
    }

    /** @param list<mixed> $tokens */
    private static function meaningfulAt(array $tokens, int $index): mixed
    {
        return $tokens[$index] ?? null;
    }

    /** @param list<mixed> $tokens */
    private static function meaningfulOffset(array $tokens, int $from, int $nth): int
    {
        $seen = 0;
        for ($i = $from + 1; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }
            $seen++;
            if ($seen === $nth) {
                return $i;
            }
        }

        throw new RuntimeException('no meaningful token ' . $nth . ' after index ' . $from);
    }

    /** @param list<mixed> $tokens */
    private static function skipWhitespace(array $tokens, int $from): int
    {
        $i = $from;
        while (is_array($tokens[$i] ?? null) && $tokens[$i][0] === T_WHITESPACE) {
            $i++;
        }

        return $i;
    }

    /** @param list<mixed> $tokens */
    private static function skipWhitespaceBack(array $tokens, int $from): int
    {
        $i = $from;
        while ($i >= 0 && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            $i--;
        }

        return $i;
    }

    /**
     * Match a bracket pair by plain-text depth scanning. Interpolated-string
     * brace opens arrive as T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES array
     * tokens but always close with a plain '}' string token, so counting only
     * the plain-text tokens keeps '{'/'}' balanced exactly like the interpreter.
     *
     * @param list<mixed> $tokens
     */
    private static function findMatching(array $tokens, int $openIndex, string $closer): ?int
    {
        $opener = $closer === ')' ? '(' : '{';
        $depth = 0;

        for ($i = $openIndex; $i < count($tokens); $i++) {
            $text = is_array($tokens[$i]) ? null : $tokens[$i];
            if ($text === $opener) {
                $depth++;
            } elseif ($text === $closer) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function nextOpenBrace(array $tokens, int $from): ?int
    {
        for ($i = $from + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if ($token === '{') {
                return $i;
            }
            if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                return $i;
            }
            if (!(is_array($token) && $token[0] === T_WHITESPACE) && $token !== '(' && $token !== ')') {
                // Alt-syntax (if(): ...) or unexpected shape — bail honestly.
                return null;
            }
        }

        return null;
    }

    private static function repoRoot(): string
    {
        $root = dirname(__DIR__);
        if (!is_file($root . '/bin/phlix')) {
            throw new RuntimeException('repo root lookup failed from ' . __DIR__);
        }

        return $root;
    }
}

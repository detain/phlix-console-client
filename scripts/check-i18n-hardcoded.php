<?php

declare(strict_types=1);

/**
 * Hardcoded-string guard for the i18n-converted screens.
 *
 * Scans the converted files for single-quoted PHP string literals that look
 * like user-facing prose and are NOT already routed through a Lang:: call.
 * Such strings bypass translation and must be moved into resources/lang/en.php.
 *
 * This is the token-based rewrite of the old regex checker: regex over raw
 * source cannot tell string literals from the code between them, and used to
 * report thousands of phantom "strings" spanning whole files (dumping file
 * contents into CI logs). token_get_all() only ever sees real literals.
 *
 * Usage:
 *   php scripts/check-i18n-hardcoded.php
 *
 * Exits 0 when clean, 1 when hardcoded user-facing strings are found.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

// Files to check (the i18n-converted screens). Keep in sync with docs/i18n.md.
$files = [
    __DIR__ . '/../src/Screen/RecommendationsScreen.php',
    __DIR__ . '/../src/Screen/DetailScreen.php',
    __DIR__ . '/../src/Ui/FilterBar.php',
];

// Known technical values that are not user-facing display strings.
$knownTechnical = [
    'name', 'year', 'rating', 'date_added', 'runtime',
    'asc', 'desc',
    'p', 'P', 's', 'S', 'C', 'r', 'R', 'f', 'F',
    'w', 'W', 'd', 'D', 'q', 'Q', 'k', 'j',
    'en', 'utf', 'UTF', 'json',
    'true', 'false', 'null',
    'view', 'init', 'update', 'boot', 'render', 'create',
    // Screen-internal non-display identifiers (array keys, API params, verbs).
    'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD',
    'limit', 'offset', 'order', 'sort', 'type', 'mode',
    'recommendations', 'shuffled_ids', 'season', 'series', 'movie',
    'episode', 'item', 'user', 'admin', 'genre', 'artist',
    'tmdb', 'imdb', 'phlix', 'markdown', 'utf-8', 'ascii',
];

/**
 * The token's id: an int T_* constant for array tokens, the character itself
 * for the single-char string tokens token_get_all() emits for '(' , ',' etc.
 *
 * @param array{0:int, 1:string, 2:int}|string $token
 */
function tokenIdentity(array|string $token): int|string
{
    return is_array_token($token) ? $token[0] : $token;
}

/**
 * @param array{0:int, 1:string, 2:int}|string $token
 */
function is_array_token(array|string $token): bool
{
    return is_array($token);
}

function isIgnorable(array|string $token): bool
{
    $id = tokenIdentity($token);

    return $id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT;
}

/**
 * Index of the previous non-whitespace token, or null at the stream start.
 *
 * @param list<array{0:int, 1:string, 2:int}|string> $tokens
 */
function prevSignificant(array $tokens, int $index): ?int
{
    for ($i = $index - 1; $i >= 0; $i--) {
        if (!isIgnorable($tokens[$i])) {
            return $i;
        }
    }

    return null;
}

/**
 * Walk back from an argument position through commas / balanced pairs to the
 * '(' that opens the surrounding call, if one exists at this nesting level.
 *
 * @param list<array{0:int, 1:string, 2:int}|string> $tokens
 */
function openingParen(array $tokens, int $index): ?int
{
    $depth = 0;
    for ($i = $index - 1; $i >= 0; $i--) {
        $id = tokenIdentity($tokens[$i]);
        if (is_string($id) && ($id === ')' || $id === ']' || $id === '}')) {
            $depth++;
            continue;
        }
        if (is_string($id) && ($id === '(' || $id === '[' || $id === '{')) {
            if ($depth === 0 && $id === '(') {
                return $i;
            }
            $depth--;
            continue;
        }
        if ($depth === 0 && (is_string($id) ? $id === ',' : $id === T_DOUBLE_ARROW)) {
            continue;
        }
        // Anything else means this literal is not a bare argument of a call.
        return null;
    }

    return null;
}

/**
 * Whether the literal token at $index is an argument of a Lang::t()/Lang::_()
 * call — decided structurally from the token stream (no text-window heuristics).
 *
 * @param list<array{0:int, 1:string, 2:int}|string> $tokens
 */
function insideLangCall(array $tokens, int $index): bool
{
    $open = prevSignificant($tokens, $index);
    if ($open === null || tokenIdentity($tokens[$open]) !== '(') {
        // A later argument ('key' => value, 'literal'): hop over ',' pairs.
        $open = openingParen($tokens, $index);
        if ($open === null) {
            return false;
        }
    }
    $name = prevSignificant($tokens, $open);
    if ($name === null || tokenIdentity($tokens[$name]) !== T_STRING) {
        return false;
    }
    $scope = prevSignificant($tokens, $name);
    if ($scope === null || tokenIdentity($tokens[$scope]) !== T_DOUBLE_COLON) {
        return false;
    }
    $class = prevSignificant($tokens, $scope);
    if ($class === null || tokenIdentity($tokens[$class]) !== T_STRING) {
        return false;
    }
    $parts = explode('\\', is_array_token($tokens[$class]) ? $tokens[$class][1] : '');

    return end($parts) === 'Lang';
}

/**
 * Whether a literal looks like a translation key ('section.some_name').
 */
function isTranslationKey(string $s): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $s);
}

/**
 * Whether a literal is clearly technical rather than display prose:
 * known values, paths/regexes, identifier-ish tokens, formats, acronyms.
 *
 * @param list<string> $knownTechnical
 */
function isTechnical(string $s, array $knownTechnical): bool
{
    if (in_array($s, $knownTechnical, true)) {
        return true;
    }
    if ($s === '') {
        return true;
    }
    // Paths / URLs / regex delimiters / sigils.
    if ($s[0] === '/' || $s[0] === '$') {
        return true;
    }
    // Single lowercase identifier-ish token (array keys, params, types).
    if (preg_match('/^[a-z][a-z0-9._-]*$/', $s)) {
        return true;
    }
    // sprintf-style format strings.
    if (preg_match('/^%[-+ 0#]*[0-9*]*\.?[0-9]*[sdgfeExXbuof]/', $s)) {
        return true;
    }
    // All-caps acronyms / key names / symbol runs.
    if (preg_match('/^[^a-z]+$/', $s)) {
        return true;
    }

    return false;
}

/**
 * Whether a literal reads like user-facing prose worth translating.
 */
function looksLikeDisplayProse(string $s): bool
{
    if (!preg_match('/[a-zA-Z]{2}/', $s)) {
        return false;
    }
    if (str_contains($s, ' ')) {
        return true;
    }
    if (str_contains($s, '…') || preg_match('/[.!?]$/', $s)) {
        return true;
    }

    // Capitalised single word (e.g. 'Loading') reads as a label.
    return (bool) preg_match('/^[A-Z][a-z]/', $s);
}

$problems = [];

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Warning: File not found: $file\n");
        continue;
    }
    /** @var list<array{0:int, 1:string, 2:int}|string> $tokens */
    $tokens = token_get_all((string) file_get_contents($file));

    foreach ($tokens as $i => $token) {
        if (!is_array_token($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $raw = $token[1];
        // Original contract: single-quoted literals only (interpolated
        // double-quoted strings stay out of scope for this guard).
        if ($raw[0] !== "'") {
            continue;
        }
        $value = stripcslashes(substr($raw, 1, -1));

        if (isTranslationKey($value) || isTechnical($value, $knownTechnical) || !looksLikeDisplayProse($value)) {
            continue;
        }
        if (insideLangCall($tokens, (int) $i)) {
            continue;
        }
        $problems[] = [basename($file), $token[2], $value];
    }
}

if ($problems !== []) {
    echo "ERROR: Found potential hardcoded user-facing string(s):\n";
    foreach ($problems as [$file, $line, $value]) {
        $preview = mb_strimwidth($value, 0, 70, '…');
        echo "  - $file:$line: '$preview'\n";
    }
    echo "\nTo fix: wrap the string in Lang::t() or Lang::_() using a key from resources/lang/en.php\n";
    echo "See docs/i18n.md for conversion guide.\n";
    exit(1);
}

echo "No hardcoded strings found. Check passed.\n";
exit(0);

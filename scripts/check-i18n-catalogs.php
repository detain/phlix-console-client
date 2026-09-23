<?php

declare(strict_types=1);

/**
 * Key-drift guard for the translation catalogs in resources/lang/.
 *
 * en.php is the source of truth. Every shipped locale catalog must carry the
 * exact same key set and the same {placeholder} tokens per key, with no
 * empty values. Drift means a user on that locale would silently see English
 * (per-key fallback) or a raw key — CI should fail on it instead.
 *
 * This mirrors tests/I18n/LocaleCatalogsTest so the invariant also holds in
 * pipelines that only run scripts (composer i18n:catalogs).
 *
 * Usage:
 *   php scripts/check-i18n-catalogs.php
 *
 * Exits 0 when all catalogs are in sync, 1 on any drift.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

$dir = __DIR__ . '/../resources/lang';
$localeFiles = ['es.php', 'fr.php', 'de.php', 'it.php', 'pt-br.php', 'ja.php'];

$load = static function (string $path): ?array {
    if (!is_file($path)) {
        return null;
    }
    /** @var mixed $data */
    $data = require $path;

    return is_array($data) ? $data : null;
};

$placeholders = static fn (string $value): array => (preg_match_all('/\{[a-z_]+\}/', $value, $m) === false) ? [] : $m[0];

$errors = [];

$en = $load("$dir/en.php");
if ($en === null) {
    echo "ERROR: resources/lang/en.php missing or does not return an array\n";
    exit(1);
}

foreach ($localeFiles as $file) {
    $catalog = $load("$dir/$file");
    if ($catalog === null) {
        $errors[] = "$file: missing or does not return an array";
        continue;
    }

    foreach (array_keys($en) as $key) {
        if (!array_key_exists($key, $catalog)) {
            $errors[] = "$file: missing key '$key'";
            continue;
        }
        $value = $catalog[$key];
        if (!is_string($value) || trim($value) === '') {
            $errors[] = "$file: empty value for '$key'";
            continue;
        }
        $enTokens = $placeholders((string) $en[$key]);
        $locTokens = $placeholders($value);
        if ($enTokens !== $locTokens) {
            $errors[] = "$file: placeholders for '$key' differ: en=[" . implode(',', $enTokens) . "] locale=[" . implode(',', $locTokens) . "]";
        }
    }

    foreach (array_keys($catalog) as $key) {
        if (!is_string($key)) {
            $errors[] = "$file: non-string key";
            continue;
        }
        if (!array_key_exists($key, $en)) {
            $errors[] = "$file: extra key '$key' not present in en.php";
        }
    }
}

if ($errors !== []) {
    echo "ERROR: translation catalog drift detected:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    echo "\nFix: add/remove/repair the keys above so each locale matches en.php exactly.\n";
    exit(1);
}

$count = count(array_keys($en));
echo "All " . count($localeFiles) . " locale catalogs match en.php ($count keys, placeholders in sync). Check passed.\n";
exit(0);

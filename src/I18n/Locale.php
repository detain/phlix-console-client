<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\I18n;

/**
 * Maps an environment locale (LANG / LC_ALL / LC_MESSAGES) onto a catalog
 * code shipped in resources/lang/.
 *
 * The vendored loader (SugarCraft\Core\I18n\T) normalizes environment values
 * to lowercase-hyphen form ("pt_BR.UTF-8" → "pt-br") and resolves a lookup by
 * trying, in order: the exact file, the base-language file, then en.php —
 * with a PER-KEY fallback to en inside translate(). This class adds the last
 * mile the vendor cannot know: which catalogs this project actually ships,
 * and the deliberate regional redirects among them (all pt* variants land on
 * the Brazilian catalog, the only Portuguese one).
 *
 * Usage at app boot (see App::boot()):
 *
 *   T::setLocale(Locale::current());
 *
 * Any unsupported language resolves to 'en', so detection can never produce
 * a locale without a catalog.
 */
final class Locale
{
    /**
     * Catalog codes shipped in resources/lang/ (the file stem before .php).
     *
     * Note 'pt-br' rather than 'pt_BR': the vendor loader lowercases and
     * hyphenates every locale before deriving the file name, so pt_BR.php
     * could never be found — pt-br.php is the canonical Brazilian catalog.
     *
     * @var list<string>
     */
    public const SUPPORTED = ['en', 'es', 'fr', 'de', 'it', 'pt-br', 'ja'];

    /**
     * Language-prefix redirects where the catalog code is not the bare
     * language tag. 'pt' has no non-regional catalog: every Portuguese
     * variant (pt, pt-pt, pt-PT …) uses the Brazilian one, which is far
     * closer for a pt-PT reader than English.
     *
     * @var array<string, string>
     */
    private const LANGUAGE_REDIRECTS = ['pt' => 'pt-br'];

    /**
     * Resolve a raw environment locale to a supported catalog code.
     *
     * Accepts any shape — 'es', 'es_ES.UTF-8', 'PT-BR', 'ja_JP@euc', 'C',
     * '' — and always returns a code present in {@see self::SUPPORTED}.
     */
    public static function resolve(string $raw): string
    {
        $normalized = self::normalize($raw);
        if ($normalized === '') {
            return 'en';
        }
        if (in_array($normalized, self::SUPPORTED, true)) {
            return $normalized;
        }

        // 'es-es', 'ja-jp', 'pt-pt' … → decide by the language prefix.
        $language = explode('-', $normalized)[0];
        $redirect = self::LANGUAGE_REDIRECTS[$language] ?? $language;

        return in_array($redirect, self::SUPPORTED, true) ? $redirect : 'en';
    }

    /**
     * Detect the locale from the environment chain (LC_ALL → LC_MESSAGES →
     * LANG, mirroring T::detect()) and resolve it to a supported catalog.
     */
    public static function current(): string
    {
        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $variable) {
            $value = $_SERVER[$variable] ?? getenv($variable);
            if (!is_string($value) || $value === '' || $value === 'C' || $value === 'POSIX') {
                continue;
            }

            return self::resolve($value);
        }

        return 'en';
    }

    /**
     * Lowercase, hyphens only, no encoding/modifier suffix:
     * 'pt_BR.UTF-8@euro' → 'pt-br'. Same shape the vendor loader derives
     * internally, restated here so resolve() can match against SUPPORTED.
     */
    private static function normalize(string $raw): string
    {
        $stripped = (string) preg_replace('/[.@].*$/', '', trim($raw));

        return strtolower(str_replace('_', '-', $stripped));
    }
}

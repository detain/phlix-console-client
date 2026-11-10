<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\I18n;

use SugarCraft\Core\I18n\Lang as BaseLang;
use SugarCraft\Core\I18n\T;

/**
 * i18n translation helper for the Phlix console client.
 *
 * Wraps SugarCraft\Core\I18n\T with the 'phlix' namespace and the
 * resources/lang/ directory as the translation catalog root.
 *
 * Usage:
 *   Lang::t('recommendations.title');        // => "For You"
 *   Lang::t('filter.search_placeholder');   // => "(type to filter)"
 *
 * Interpolation with {name} placeholders:
 *   Lang::t('detail.more_cast', ['count' => 5]);  // => "  +5 more"
 */
final class Lang extends BaseLang
{
    /** Translation namespace for this library. */
    protected const NAMESPACE = 'phlix';

    /** Absolute path to the lang/ directory containing per-locale .php files. */
    protected const DIR = __DIR__ . '/../../resources/lang';

    /**
     * Translate a message key within the phlix namespace.
     *
     * Alias for the inherited t() method, kept for brevity at call sites.
     *
     * @param string              $key    Dot-separated key relative to namespace (e.g. 'login.title')
     * @param array<string,mixed> $params Interpolation parameters for {name} placeholders
     * @return string Translated string
     */
    public static function t(string $key, array $params = []): string
    {
        // [CU-046: review console__srphp #8] This arm looks like hot-path waste but is
        // load-bearing. Measured against the vendored T::register(): a str_contains
        // throw-guard plus `self::$namespaces[$ns] ??= $dir` — an O(1) static-array
        // write, zero filesystem reads, zero syscalls per call. The per-call form is
        // the documented recovery contract (BaseLang: "safe to put inline at the top
        // of every lookup helper"): T::reset() wipes namespaces, cache and locale
        // together, and suites re-arm via reset in setUp, so a register-once-at-boot
        // memo would strand every later lookup on an empty table and silently degrade
        // the whole UI to raw keys. TranslatorTest pins the self-heal; converting this
        // line into a bool-memo reddens it.
        T::register(static::NAMESPACE, static::DIR);

        /** @var array<string, float|int|string> $typedParams */
        $typedParams = $params;

        return T::translate(static::NAMESPACE . '.' . $key, $typedParams);
    }

    /**
     * Shorthand alias for t() — matches the gettext underscore convention.
     *
     * @param string $msgid The message key to translate
     * @return string Translated string
     */
    public static function _(string $msgid): string
    {
        return self::t($msgid);
    }
}

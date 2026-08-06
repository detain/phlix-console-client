<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 *   Lang::t('login.title');                  // => "Sign in to Phlix."
 *   Lang::t('filter.search_placeholder');   // => "(type to filter)"
 *
 * Interpolation with {name} placeholders:
 *   Lang::t('errors.not_found', ['id' => $id]);
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
    /**
     * @param array<string, mixed> $params
     */
    public static function t(string $key, array $params = []): string
    {
        // T::register() is idempotent, so calling it on every t() call is safe.
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

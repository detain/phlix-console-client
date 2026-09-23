<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\I18n;

use Phlix\Console\I18n\Lang;
use Phlix\Console\I18n\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;

/**
 * Parity and sanity gates for the non-English catalogs in resources/lang/.
 *
 * en.php is the single source of truth; every locale file must carry exactly
 * its key set (same order), identical {placeholder} tokens per key, non-empty
 * values, no accidental copy-paste of English outside the documented
 * cognate/untranslatable whitelist, and (for ja) real Japanese text.
 *
 * The same key/placeholder invariants are enforced standalone by
 * scripts/check-i18n-catalogs.php so catalog drift fails CI without the suite.
 */
final class LocaleCatalogsTest extends TestCase
{
    /** @var list<string> the shipped non-English catalogs */
    private const LOCALES = ['es', 'fr', 'de', 'it', 'pt-br', 'ja'];

    /**
     * Keys whose value legitimately equals English:
     * - filter.order_asc / filter.order_desc — 'asc'/'desc' are the standard
     *   width-constrained abbreviations in es/fr/de/it/pt-BR software UIs
     *   (Japanese inflects: 昇順/降順).
     * - detail.cast_label = 'Cast' in Italian — established loanword used
     *   verbatim by Italian film media.
     * - detail.item = 'item' in Brazilian Portuguese — a registered loanword.
     *
     * @var array<string, list<string>>
     */
    private const EN_IDENTITY_WHITELIST = [
        'es' => ['filter.order_asc', 'filter.order_desc'],
        'fr' => ['filter.order_asc', 'filter.order_desc'],
        'de' => ['filter.order_asc', 'filter.order_desc'],
        'it' => ['detail.cast_label', 'filter.order_asc', 'filter.order_desc'],
        'pt-br' => ['detail.item', 'filter.order_asc', 'filter.order_desc'],
        'ja' => [],
    ];

    /** @var array<string, array<string, string>> locale => catalog, lazily loaded */
    private array $catalogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        T::reset();
    }

    protected function tearDown(): void
    {
        T::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function localeRows(): array
    {
        $rows = [];
        foreach (self::LOCALES as $locale) {
            $rows[$locale] = [$locale];
        }

        return $rows;
    }

    #[DataProvider('localeRows')]
    public function testKeySetMatchesEnglishExactlyIncludingOrder(string $locale): void
    {
        self::assertSame(
            array_keys($this->catalog('en')),
            array_keys($this->catalog($locale)),
            "$locale: key set/order drifted from en.php"
        );
    }

    #[DataProvider('localeRows')]
    public function testPlaceholderTokensMatchEnglishPerKey(string $locale): void
    {
        foreach ($this->catalog('en') as $key => $enValue) {
            self::assertSame(
                self::placeholders($enValue),
                self::placeholders($this->catalog($locale)[$key]),
                "$locale: $key placeholder tokens differ from en"
            );
        }
    }

    #[DataProvider('localeRows')]
    public function testNoEmptyValues(string $locale): void
    {
        foreach ($this->catalog($locale) as $key => $value) {
            self::assertNotSame('', trim($value), "$locale: $key is empty");
        }
    }

    #[DataProvider('localeRows')]
    public function testEnglishLeftoversEqualWhitelistExactly(string $locale): void
    {
        $identical = [];
        foreach ($this->catalog('en') as $key => $enValue) {
            if ($this->catalog($locale)[$key] === $enValue) {
                $identical[] = $key;
            }
        }

        $expected = self::EN_IDENTITY_WHITELIST[$locale];
        sort($identical);
        sort($expected);
        self::assertSame($expected, $identical, "$locale: untranslated values outside the whitelist");
    }

    #[DataProvider('localeRows')]
    public function testLeadingWhitespaceStructureMatchesEnglish(string $locale): void
    {
        foreach ($this->catalog('en') as $key => $enValue) {
            self::assertSame(
                self::leadingWhitespace($enValue),
                self::leadingWhitespace($this->catalog($locale)[$key]),
                "$locale: $key leading whitespace differs from en (layout-sensitive)"
            );
        }
    }

    public function testJapaneseCatalogContainsJapanese(): void
    {
        foreach ($this->catalog('ja') as $key => $value) {
            self::assertSame(
                1,
                preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $value),
                "ja: $key has no CJK characters"
            );
        }
    }

    public function testLatinCatalogsContainNoAccidentalJapanese(): void
    {
        foreach (['es', 'fr', 'de', 'it', 'pt-br'] as $locale) {
            foreach ($this->catalog($locale) as $key => $value) {
                self::assertSame(
                    0,
                    preg_match('/[\x{3040}-\x{30FF}]/u', $value),
                    "$locale: $key unexpectedly contains kana"
                );
            }
        }
    }

    #[DataProvider('localeRows')]
    public function testCatalogHasNoDuplicateKeyLiterals(string $locale): void
    {
        $source = (string) file_get_contents(__DIR__ . "/../../resources/lang/$locale.php");
        preg_match_all("/^    '([a-z_]+\.[a-z_]+)'/m", $source, $matches);

        self::assertSame(
            count($matches[1]),
            count(array_unique($matches[1])),
            "$locale: duplicate key literal in file"
        );
        self::assertCount(count($this->catalog($locale)), $matches[1], "$locale: parsed key count != array count");
    }

    // ---- loader integration -------------------------------------------

    #[DataProvider('localeRows')]
    public function testTranslateReturnsLocaleValueAfterSetLocale(string $locale): void
    {
        Lang::t('recommendations.title'); // registers the real catalog directory

        T::setLocale($locale);
        self::assertSame($this->catalog($locale)['recommendations.title'], Lang::t('recommendations.title'));
        self::assertSame($this->catalog($locale)['detail.load_failed'], Lang::t('detail.load_failed'));
    }

    public function testInterpolationWorksInEveryLocale(): void
    {
        Lang::t('recommendations.title');

        foreach (self::LOCALES as $locale) {
            T::setLocale($locale);
            $rendered = Lang::t('detail.more_cast', ['count' => 7]);

            self::assertStringNotContainsString('{count}', $rendered, "$locale: {count} not interpolated");
            self::assertStringContainsString('7', $rendered, "$locale: interpolated count missing in '$rendered'");
        }
    }

    public function testBrazilianPortugueseResolvesFromPosixShapeToCatalog(): void
    {
        // The headline pt_BR story, end to end: env shape → code → pt-br.php.
        Lang::t('recommendations.title');
        T::setLocale(Locale::resolve('pt_BR.UTF-8'));

        self::assertSame('Para você', Lang::t('recommendations.title'));
    }

    public function testJapaneseRegionVariantReachesJaCatalog(): void
    {
        Lang::t('recommendations.title');
        T::setLocale(Locale::resolve('JA_JP'));

        self::assertSame('おすすめ', Lang::t('recommendations.title'));
    }

    public function testMissingKeysFallBackToEnglishPerKeyWithinLocale(): void
    {
        // Pin the vendor loader's per-key fallback: a locale file that only
        // translates SOME keys must still yield the English value for the
        // rest. This is what lets a locale lag en.php during translation
        // work — and why the key-drift guard fails CI on it instead.
        $dir = (string) realpath(sys_get_temp_dir()) . '/phlix-lang-fixture-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        copy(__DIR__ . '/../../resources/lang/en.php', "$dir/en.php");
        file_put_contents("$dir/es.php", "<?php\n\nreturn ['recommendations.title' => 'Título parcial'];\n");

        try {
            T::reset();
            T::register('phlix', $dir);
            T::setLocale('es');

            self::assertSame('Título parcial', T::translate('phlix.recommendations.title'));
            // Key missing from es.php → falls back to the en.php value, not the raw key.
            self::assertSame('Loading…', T::translate('phlix.detail.loading'));
            // Key missing everywhere → the raw namespaced key surfaces visibly.
            self::assertSame('phlix.ghost.key', T::translate('phlix.ghost.key'));
        } finally {
            foreach ((glob("$dir/*.php") ?: []) as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function testUnsupportedLocaleFallsBackToEnglishCatalog(): void
    {
        Lang::t('recommendations.title');
        T::setLocale(Locale::resolve('zh_CN.UTF-8'));

        self::assertSame('For You', Lang::t('recommendations.title'));
    }

    // ---- helpers --------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        if (!isset($this->catalogs[$locale])) {
            /** @var array<string, string> $data */
            $data = require __DIR__ . "/../../resources/lang/$locale.php";
            $this->catalogs[$locale] = $data;
        }

        return $this->catalogs[$locale];
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $value): array
    {
        preg_match_all('/\{[a-z_]+\}/', $value, $matches);

        return $matches[0];
    }

    private static function leadingWhitespace(string $value): string
    {
        return (string) preg_match('/^\s*/u', $value, $m) ? $m[0] : '';
    }
}

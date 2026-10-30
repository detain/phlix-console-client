<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

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
        // 'player.hint_sleep' is the glyph-only countdown token ('  z ⏰ ') —
        // there is no word to translate, so every locale mirrors en byte-for-
        // byte (review M-CONSOLE-64; same class as the asc/desc order labels).
        'es' => ['filter.order_asc', 'filter.order_desc', 'player.hint_sleep'],
        'fr' => ['filter.order_asc', 'filter.order_desc', 'player.hint_sleep'],
        'de' => ['filter.order_asc', 'filter.order_desc', 'player.hint_sleep'],
        'it' => ['detail.cast_label', 'filter.order_asc', 'filter.order_desc', 'player.hint_sleep'],
        'pt-br' => ['detail.item', 'filter.order_asc', 'filter.order_desc', 'player.hint_sleep'],
        'ja' => ['player.hint_sleep'],
    ];

    /**
     * The established host-role term per catalog as a case-insensitive
     * pattern (Italian software convention keeps the English loanword
     * 'host', as ja keeps ホスト and de the compound Gruppenhost — one
     * uniform term across each catalog).
     *
     * @var array<string, string>
     */
    private const HOST_ROLE_TERMS = [
        'es' => '/anfitri[oó]n/iu',
        'fr' => '/h[oô]te/iu',
        'de' => '/host/iu',
        'it' => '/\bhost\b/iu',
        'pt-br' => '/anfitri[ãa]o/iu',
        'ja' => '/ホスト/u',
    ];

    /**
     * Each catalog's stem for GUEST (morphological net: it ospite/ospiti).
     * None of these may match a value whose English counterpart does not
     * say "guest" (review console__copywriter M-CONSOLE-08 found it.php
     * inverting exactly this).
     *
     * @var array<string, string>
     */
    private const GUEST_ROLE_TERMS = [
        'es' => '/hu[eé]spd/iu',
        'fr' => '/invit/iu',
        'de' => '/gast/iu',
        'it' => '/ospit/iu',
        'pt-br' => '/h[oó]sped/iu',
        'ja' => '/ゲスト/u',
    ];

    /**
     * The established "missing" term per catalog (morphological nets: de
     * fehlt/fehlen/Fehlende, es que falta/que faltan/faltante ...). Every
     * value whose English says "missing" must carry it — review
     * console__copywriter M-CONSOLE-09 found es.php inverting the term.
     *
     * @var array<string, string>
     */
    private const MISSING_TERMS = [
        'es' => '/que falt|faltante/iu',
        'fr' => '/manquant/iu',
        'de' => '/fehl/iu',
        'it' => '/mancant/iu',
        'pt-br' => '/faltant/iu',
        'ja' => '/欠落/u',
    ];

    /**
     * Each catalog's stems for UNWATCHED ("left to watch") — the inversion
     * M-CONSOLE-09 cured. None may appear in a value whose English
     * counterpart does not say "unwatched"; en.php currently never does, so
     * these are absolute tripwires. The loose pt-br '[aà] assistir' form is
     * deliberately absent: it collides with the benign 'comece a assistir'
     * copy in recommendations.empty (probed at 0ea9bdf), so pt-br pins its
     * explicit não-assistido/por-assistir phrasings only.
     *
     * @var array<string, string>
     */
    private const UNWATCHED_TERMS = [
        'es' => '/sin\s+ver|no\s+visto/iu',
        'fr' => '/non\s+vu|[àa]\s+voir/iu',
        'de' => '/ungesehen|nicht\s+gesehen/iu',
        'it' => '/non\s+visto|da\s+vedere/iu',
        'pt-br' => '/n[ãa]o\s+assistido|por\s+assistir/iu',
        'ja' => '/未視聴/u',
    ];

    /**
     * The established "group" family term per catalog, taken from the
     * majority sibling usage across each syncplay.* key cluster
     * (review console__copywriter M-CONSOLE-10: group_limit_reached
     * invented a sixth synonym for the group in EVERY non-en catalog —
     * de 'Watch-Parties' vs 'synchronisierte Gruppe', es 'salas de
     * visionado' vs 'grupo de visionado', fr 'soirées synchronisées' vs
     * 'groupe synchronisé', it 'sale di visione' vs 'gruppo di visione',
     * ja 'ウォッチパーティ' vs 'ウォッチグループ', pt-br 'sessões
     * sincronizadas' vs 'grupo de exibição').
     *
     * @var array<string, string>
     */
    private const GROUP_FAMILY_TERMS = [
        'es' => '/\bgrupos?\s+de\s+visionado/iu',
        'fr' => '/\bgroupes?\s+synchronis[ée]/iu',
        'de' => '/\bsynchronisierte[nr]?\s+Gruppen?/iu',
        'it' => '/\bgrupp[oi]\s+di\s+visione/iu',
        'pt-br' => '/\bgrupos?\s+de\s+exibi[cç][aã]o/iu',
        'ja' => '/ウォッチグループ/u',
    ];

    /**
     * The invented outlier synonyms from the same census — each family is
     * bound to group_limit_reached alone and has zero legitimate use
     * anywhere in its catalog (pt-br's net is deliberately the compound
     * 'sessões sincronizadas': the bare 'sessão' stem is the legitimate
     * 'session' term in auth.session_expired and 'sincronização' the
     * 'sync error' term in syncplay.unknown_error, both probed at the
     * parent catalogs; it's bare 'sala' likewise appears nowhere else).
     *
     * @var array<string, string>
     */
    private const GROUP_OUTLIER_TERMS = [
        'es' => '/\bsalas?\s+de\s+visionado/iu',
        'fr' => '/\bsoir[ée]e/iu',
        'de' => '/watch[\s-]*parti/iu',
        'it' => '/\bsale?\s+di\s+visione/iu',
        'pt-br' => '/sess[õo]es?\s+sincronizad/iu',
        'ja' => '/ウォッチパーティ/u',
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

    /**
     * ja values that are translation-free glyph/whitespace tokens (the sleep
     * countdown is '  z ⏰ ' + a number in every locale, review M-CONSOLE-64)
     * — exempt from the "contains CJK" law, same family as the EN-identity
     * whitelist above.
     *
     * @var list<string>
     */
    private const JA_GLYPH_ONLY_KEYS = ['player.hint_sleep'];

    public function testJapaneseCatalogContainsJapanese(): void
    {
        foreach ($this->catalog('ja') as $key => $value) {
            if (in_array($key, self::JA_GLYPH_ONLY_KEYS, true)) {
                continue;
            }

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

    /**
     * Both-directions host/guest semantic guard derived from en.php:
     * (1) every value whose English says "host" must carry that locale's
     * host term; (2) a locale's guest term may only appear where the
     * English value itself says "guest".
     */
    #[DataProvider('localeRows')]
    public function testHostRoleStringsSayHostAndNeverTheGuestTerm(string $locale): void
    {
        foreach ($this->catalog('en') as $key => $enValue) {
            $value = $this->catalog($locale)[$key];

            if (preg_match('/\bhost\b/i', $enValue) === 1) {
                self::assertMatchesRegularExpression(
                    self::HOST_ROLE_TERMS[$locale],
                    $value,
                    "$locale: $key inverts the host role (expected host term)"
                );
            }

            if (preg_match('/\bguest\b/i', $enValue) !== 1) {
                self::assertDoesNotMatchRegularExpression(
                    self::GUEST_ROLE_TERMS[$locale],
                    $value,
                    "$locale: $key uses a guest term where English says no guest"
                );
            }
        }
    }

    /**
     * Both-directions missing/unwatched semantic guard derived from en.php
     * (review console__copywriter M-CONSOLE-09):
     * (1) every value whose English says "missing" must carry that locale's
     * established missing term — es had rendered the missing-episodes
     * warning as 'episodio sin ver' ('episode left to watch'), contradicting
     * its own correct 'los episodios que faltan' one section above;
     * (2) a locale's unwatched term may only appear where the English value
     * itself says "unwatched".
     */
    #[DataProvider('localeRows')]
    public function testMissingEpisodeStringsSayMissingAndNeverUnwatched(string $locale): void
    {
        foreach ($this->catalog('en') as $key => $enValue) {
            $value = $this->catalog($locale)[$key];

            if (preg_match('/\bmissing\b/i', $enValue) === 1) {
                self::assertMatchesRegularExpression(
                    self::MISSING_TERMS[$locale],
                    $value,
                    "$locale: $key says 'missing' in English but not in $locale"
                );
            }

            if (preg_match('/\bunwatched\b/i', $enValue) !== 1) {
                self::assertDoesNotMatchRegularExpression(
                    self::UNWATCHED_TERMS[$locale],
                    $value,
                    "$locale: $key uses an unwatched term where English says nothing unwatched"
                );
            }
        }
    }

    /**
     * Group-family guard for the M-CONSOLE-10 outlier key:
     * syncplay.group_limit_reached must carry the catalog's own
     * established group term, and the invented synonym family must
     * appear nowhere in that catalog at all. The present-side is pinned
     * to this one key rather than derived en-wide because several
     * sibling keys use legitimate anaphoric shortening (ja
     * not_in_group 'グループ', de join_failed 'Der Gruppe', fr not_host
     * 'du groupe') — broadening the family to every mention would be a
     * rename of established terms, which is the owner-gated room-vs-
     * watch-group ruling (CON-07), not this cure.
     */
    #[DataProvider('localeRows')]
    public function testGroupLimitReachedUsesTheCatalogsOwnGroupTerm(string $locale): void
    {
        $value = $this->catalog($locale)['syncplay.group_limit_reached'];

        self::assertMatchesRegularExpression(
            self::GROUP_FAMILY_TERMS[$locale],
            $value,
            "$locale: syncplay.group_limit_reached does not carry the catalog's established group term"
        );
    }

    /**
     * Absolute tripwire: no value in any catalog may carry that locale's
     * invented group synonym (the family M-CONSOLE-10 cured; it belongs
     * to no key).
     */
    #[DataProvider('localeRows')]
    public function testInventedGroupSynonymsAppearNowhereInCatalog(string $locale): void
    {
        foreach ($this->catalog($locale) as $key => $value) {
            self::assertDoesNotMatchRegularExpression(
                self::GROUP_OUTLIER_TERMS[$locale],
                $value,
                "$locale: $key uses an invented group synonym for the watch group"
            );
        }
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

<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\I18n;

use PHPUnit\Framework\TestCase;

/**
 * CON-S20 vocabulary pins: one sign-in term per language (ja), one address
 * register per catalog (de/es/it informal, fr formal), de singular/plural
 * counters distinct, and a BrowseScreen section banner in every catalog.
 */
final class LocaleVocabularyPinTest extends TestCase
{
    private const LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt-br', 'ja'];

    /** @return array<string,string> */
    private function cat(string $l): array
    {
        return require __DIR__ . '/../../resources/lang/' . $l . '.php';
    }

    public function testJapaneseUsesSigninTermOnly(): void
    {
        foreach ($this->cat('ja') as $k => $v) {
            self::assertStringNotContainsString('ログイン', $v, $k);
            self::assertStringNotContainsString('ログアウト', $v, $k);
        }
    }

    public function testBrowseRegisterMatchesCatalog(): void
    {
        foreach (['de', 'es', 'it'] as $l) {
            $c = $this->cat($l);
            foreach (['browse.loading', 'browse.no_libraries_admin', 'browse.no_libraries_member'] as $k) {
                $pattern = '/\b(Sie|Ihre|Ihr|Drücken|Bitten|Pulse|Pida|usted)\b/u';
                self::assertDoesNotMatchRegularExpression($pattern, $c[$k], "$l $k");
            }
        }
    }

    /**
     * Whole-catalog register pin [CON-S20 minor]: the three-key browse pin
     * only caught leakage where it was looked for. de/es/it speak the
     * informal register in EVERY value; fr speaks the formal one.
     *
     * @return array<string, array{string, string}>
     */
    public static function registers(): array
    {
        return [
            'de informal' => ['de', '/\b(Sie|Ihre|Ihren|Ihrem|Ihrer|Ihres|Ihr|Ihnen)\b/u'],
            'es informal' => ['es', '/\busted(?:es)?\b|\b(?:Pulse|Pida|Introduzca|Seleccione|Escriba|Elija|Verifique|Compruebe|Espere|Inicie|Intente|Utilice)\b/iu'],
            'it informal' => ['it', '/\b(?:Lei|Suo|Sua|Suoi|Sue|Premere|Inserisca|Selezioni|Verifichi|Riprovi|Prema|Attenda|Scelga|Digiti)\b/u'],
            'fr formal' => ['fr', '/\b(?:tu|ton|ta|tes|toi|Tu|Ton|Ta|Tes)\b/u'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('registers')]
    public function testWholeCatalogKeepsItsAddressRegister(string $locale, string $leak): void
    {
        foreach ($this->cat($locale) as $key => $value) {
            self::assertDoesNotMatchRegularExpression($leak, $value, "$locale $key leaks the other register");
        }
    }

    public function testGermanPluralsDifferFromSingulars(): void
    {
        $c = $this->cat('de');
        foreach (['detail.season', 'detail.episode', 'detail.item'] as $k) {
            self::assertNotSame($c[$k], $c[$k . '_plural'], $k);
        }
        self::assertNotSame($c['search.results_one'], $c['search.results_many']);
        self::assertNotSame($c['library.items_one'], $c['library.items_many']);
    }

    public function testEveryCatalogHasBrowseBanner(): void
    {
        foreach (self::LOCALES as $l) {
            $src = (string) file_get_contents(__DIR__ . '/../../resources/lang/' . $l . '.php');
            self::assertMatchesRegularExpression("/\/\/ ---- BrowseScreen[^\n]*\n\s*'browse\.loading'/", $src, $l);
        }
    }
}

<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\I18n;

use Phlix\Console\I18n\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for environment-locale → catalog-code resolution.
 *
 * @see Locale
 */
final class LocaleTest extends TestCase
{
    private const ENV_VARS = ['LC_ALL', 'LC_MESSAGES', 'LANG'];

    /** @var array<string, array{0: mixed, 1: string|false}> variable => [server value, getenv value] */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV_VARS as $variable) {
            $this->savedEnv[$variable] = [$_SERVER[$variable] ?? null, getenv($variable)];
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_VARS as $variable) {
            [$serverValue, $envValue] = $this->savedEnv[$variable];
            if ($serverValue === null) {
                unset($_SERVER[$variable]);
            } else {
                $_SERVER[$variable] = $serverValue;
            }
            if ($envValue === false) {
                putenv($variable);
            } else {
                putenv("$variable=$envValue");
            }
        }
        parent::tearDown();
    }

    /**
     * Pin the whole resolution table: encoding/modifier stripping, case and
     * separator insensitivity, regional redirects, pt→pt-br, unsupported→en.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function resolveProvider(): array
    {
        return [
            // Bare codes pass through.
            'bare es' => ['es', 'es'],
            'bare fr' => ['fr', 'fr'],
            'bare de' => ['de', 'de'],
            'bare it' => ['it', 'it'],
            'bare pt-br' => ['pt-br', 'pt-br'],
            'bare ja' => ['ja', 'ja'],
            'bare en' => ['en', 'en'],
            // POSIX shapes with encoding.
            'es_ES.UTF-8' => ['es_ES.UTF-8', 'es'],
            'fr_FR.ISO-8859-1' => ['fr_FR.ISO-8859-1', 'fr'],
            'de_DE.UTF-8' => ['de_DE.UTF-8', 'de'],
            'it_IT.UTF-8' => ['it_IT.UTF-8', 'it'],
            'pt_BR.utf8 lower encoding' => ['pt_BR.utf8', 'pt-br'],
            'pt_BR.UTF-8' => ['pt_BR.UTF-8', 'pt-br'],
            'ja_JP.UTF-8' => ['ja_JP.UTF-8', 'ja'],
            'modifier stripped' => ['de_DE.UTF-8@euro', 'de'],
            // Case insensitivity.
            'upper JA_JP' => ['JA_JP', 'ja'],
            'upper PT_BR' => ['PT_BR', 'pt-br'],
            'mixed Es-es' => ['Es-es', 'es'],
            // Dashed region variants.
            'pt-BR' => ['pt-BR', 'pt-br'],
            'pt-PT lands on brazilian' => ['pt-PT', 'pt-br'],
            'bare pt lands on brazilian' => ['pt', 'pt-br'],
            'es-MX' => ['es-MX', 'es'],
            'fr-CA' => ['fr-CA', 'fr'],
            'de-AT' => ['de-AT', 'de'],
            'ja-JP' => ['ja-JP', 'ja'],
            'en-GB' => ['en-GB', 'en'],
            // Unsupported → English.
            'chinese unsupported' => ['zh_CN.UTF-8', 'en'],
            'korean unsupported' => ['ko_KR', 'en'],
            'dutch unsupported' => ['nl', 'en'],
            'russian unsupported' => ['ru_RU.KOI8', 'en'],
            'C locale' => ['C', 'en'],
            'POSIX locale' => ['POSIX', 'en'],
            'empty' => ['', 'en'],
            'garbage' => ['garbage-x99', 'en'],
            'value is not a locale (LANG=UTF-8 shape)' => ['UTF-8', 'en'],
        ];
    }

    #[DataProvider('resolveProvider')]
    public function testResolveMapsEnvironmentLocalesOntoCatalogCodes(string $raw, string $expected): void
    {
        self::assertSame($expected, Locale::resolve($raw));
        self::assertContainsCatalogCode($expected);
    }

    public function testCurrentPrefersLcAllOverOtherVariables(): void
    {
        self::setLocaleEnv('de_DE.UTF-8', 'it_IT.UTF-8', 'en_US.UTF-8');

        self::assertSame('de', Locale::current());
    }

    public function testCurrentFallsThroughChainToLang(): void
    {
        self::setLocaleEnv('', '', 'ja_JP.UTF-8');

        self::assertSame('ja', Locale::current());
    }

    public function testCurrentUsesLcMessagesWhenLcAllUnset(): void
    {
        self::setLocaleEnv('', 'pt_BR.UTF-8', 'de_DE.UTF-8');

        self::assertSame('pt-br', Locale::current());
    }

    public function testCurrentDefaultsToEnglishForCPosixAndNothing(): void
    {
        self::setLocaleEnv('C', '', '');
        self::assertSame('en', Locale::current());

        self::setLocaleEnv('POSIX', '', '');
        self::assertSame('en', Locale::current());

        self::setLocaleEnv('', '', '');
        self::assertSame('en', Locale::current());
    }

    public function testEverySupportedCodeShipsACatalogFile(): void
    {
        $dir = dirname(__DIR__, 2) . '/resources/lang';

        foreach (Locale::SUPPORTED as $code) {
            self::assertFileExists("$dir/$code.php", "supported locale '$code' has no catalog file");
        }
    }

    private static function setLocaleEnv(string $lcAll, string $lcMessages, string $lang): void
    {
        $_SERVER['LC_ALL'] = $lcAll;
        $_SERVER['LC_MESSAGES'] = $lcMessages;
        $_SERVER['LANG'] = $lang;
        putenv("LC_ALL=$lcAll");
        putenv("LC_MESSAGES=$lcMessages");
        putenv("LANG=$lang");
    }

    private static function assertContainsCatalogCode(string $code): void
    {
        self::assertContains($code, Locale::SUPPORTED);
    }
}

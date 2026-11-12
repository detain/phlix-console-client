<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Api\Dto\SyncPlayGroup;
use Phlix\Console\Ui\SyncPlayModal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Ansi;

/**
 * One canonical group noun per language (CON-19 / CON-28).
 *
 * The SyncPlay modal used to call the thing a "room" ('Public Rooms:',
 * '+ Create new room', 'Create Room', 'Joining room…') while every
 * syncplay.* catalog line — the errors the same flow surfaces — calls it
 * a "watch group". The noun-bearing modal lines now route through the
 * syncplay.modal_* keys, so each locale's modal carries that catalog's
 * own glossary term and English never says "room" to the user.
 *
 * Owner IA ruling (room vs watch-group) is estate-open: this pins the
 * current en.php term. When the ruling lands, flip the catalogs and this
 * fixture together.
 */
final class SyncPlayModalGlossaryTest extends TestCase
{
    /** Each locale's established group term (mirrors LocaleCatalogsTest::GROUP_FAMILY_TERMS + en). */
    private const GROUP_TERMS = [
        'en' => '/\bwatch groups?\b/iu',
        'es' => '/\bgrupos?\s+de\s+visionado/iu',
        'fr' => '/\bgroupes?\s+synchronis[ée]/iu',
        'de' => '/\bsynchronisierte[nr]?\s+Gruppen?/iu',
        'it' => '/\bgrupp[oi]\s+di\s+visione/iu',
        'pt-br' => '/\bgrupos?\s+de\s+exibi[cç][aã]o/iu',
        'ja' => '/ウォッチグループ/u',
    ];

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
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        $rows = [];
        foreach (array_keys(self::GROUP_TERMS) as $locale) {
            $rows[$locale] = [$locale];
        }

        return $rows;
    }

    /**
     * @return array<string, string> state label => stripped render
     */
    private function renders(): array
    {
        $rooms = [new SyncPlayGroup('g1', 'Movie Night', true, 3)];
        $list = SyncPlayModal::open($rooms, 80, 24);
        [$create] = $list->select(); // cursor 0 = the create option

        return [
            'list' => Ansi::strip($list->render('')),
            'create' => Ansi::strip($create->render('')),
            'joining' => Ansi::strip($list->joining()->render('')),
        ];
    }

    #[DataProvider('locales')]
    public function testEveryNounBearingStateSpeaksTheCatalogsGroupTerm(string $locale): void
    {
        T::setLocale($locale);

        foreach ($this->renders() as $state => $screen) {
            self::assertMatchesRegularExpression(
                self::GROUP_TERMS[$locale],
                $screen,
                "$locale: the $state state does not carry the catalog's group term",
            );
        }
    }

    #[DataProvider('locales')]
    public function testTheModalNeverSaysRoom(string $locale): void
    {
        T::setLocale($locale);

        foreach ($this->renders() as $state => $screen) {
            self::assertDoesNotMatchRegularExpression(
                '/\brooms?\b/iu',
                $screen,
                "$locale: the $state state still calls the watch group a room",
            );
        }
    }

    public function testCreateFormTitleAndHeaderBothCarryTheGlossaryTerm(): void
    {
        T::setLocale('en');

        $screen = $this->renders()['create'];

        self::assertStringContainsString(' Create watch group ', $screen, 'panel title');
        self::assertStringContainsString('  Create watch group', $screen, 'bold header');
    }
}

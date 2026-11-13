<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Api\Dto\SyncPlayGroup;
use Phlix\Console\Ui\SyncPlayModal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Console\I18n\Lang;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

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
            // The error state: the composed context the PlayerScreen hands the
            // modal speaks the same glossary term [CON-S18].
            'error' => Ansi::strip($list->withError(
                Lang::t('syncplay.ctx_join_failed') . ': ' . Lang::t('syncplay.group_full'),
            )->render('')),
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

    #[DataProvider('locales')]
    public function testEveryRoomFlowSentenceSpeaksTheGroupTermAndNeverSaysRoom(string $locale): void
    {
        T::setLocale($locale);

        foreach (['ctx_load_failed', 'ctx_create_failed', 'ctx_join_failed', 'default_group_name', 'status_not_in_group'] as $key) {
            $text = Lang::t('syncplay.' . $key);
            self::assertMatchesRegularExpression(self::GROUP_TERMS[$locale], $text, "$locale: $key");
            self::assertDoesNotMatchRegularExpression('/\brooms?\b/iu', $text, "$locale: $key says room");
        }
    }

    /**
     * At MIN_WIDTH (a ~38-column terminal) the longest localized strings used
     * to wrap and the de panel title clipped mid-word [CON-S18]. Every row now
     * fits the box and any cut is an ellipsis, never a mid-word clip.
     */
    #[DataProvider('locales')]
    public function testMinWidthRenderFitsTheBoxInEveryState(string $locale): void
    {
        T::setLocale($locale);

        $rooms = [new SyncPlayGroup('g1', 'A very long watch group name indeed', true, 3)];
        $list = SyncPlayModal::open($rooms, 38, 24);
        [$create] = $list->select();
        $states = [
            'list' => $list,
            'create' => $create,
            'joining' => $list->joining(),
            'error' => $list->withError(Lang::t('syncplay.ctx_join_failed') . ': ' . Lang::t('syncplay.group_full')),
        ];
        $width = $list->dims()['width'];
        self::assertSame(30, $width, 'MIN_WIDTH geometry');

        foreach ($states as $state => $modal) {
            foreach (explode("\n", Ansi::strip($modal->render(''))) as $line) {
                self::assertLessThanOrEqual($width, Width::string(rtrim($line)), "$locale/$state row overflows: $line");
            }
        }

        $title = explode("\n", Ansi::strip($create->render('')))[0];
        if ($locale === 'de') {
            self::assertStringNotContainsString('erst ', $title, 'de title is not clipped mid-word');
            self::assertStringContainsString('…', $title, 'the long de title is ellipsised');
        }
    }
}

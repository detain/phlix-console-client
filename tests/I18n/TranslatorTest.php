<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\I18n;

use Phlix\Console\I18n\Lang;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;

/**
 * Tests for the i18n translation system.
 *
 * @see Lang
 * @see T
 */
final class TranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset T's global state before each test to ensure clean state.
        T::reset();
    }

    public function testUnderscoreReturnsTranslatedString(): void
    {
        // The underscore method is an alias for t() with no params.
        $result = Lang::_('recommendations.title');

        self::assertSame('For You', $result);
    }

    public function testTWithKeyReturnsTranslatedString(): void
    {
        $result = Lang::t('recommendations.title');

        self::assertSame('For You', $result);
    }

    public function testTWithInterpolation(): void
    {
        // The 'detail.more_cast' key has a {count} placeholder.
        $result = Lang::t('detail.more_cast', ['count' => 5]);

        self::assertSame('  +5 more', $result);
    }

    public function testUnderscoreWithUnknownKeyReturnsFullKey(): void
    {
        // Unknown keys fall back to returning the full key (including namespace prefix).
        // This is by design - it makes missing translations visible rather than silent.
        $result = Lang::_('nonexistent.key');

        self::assertSame('phlix.nonexistent.key', $result);
    }

    public function testTDetectsLocaleFromEnvironment(): void
    {
        // T::detect() reads from environment variables.
        $locale = T::detect();

        // It should be normalized (lowercase, no encoding suffix).
        self::assertMatchesRegularExpression('/^[a-z-]+$/', $locale);
    }

    public function testSetLocaleChangesActiveLocale(): void
    {
        T::setLocale('en');

        self::assertSame('en', T::locale());
    }

    public function testLangNamespaceIsRegistered(): void
    {
        // Registering the same namespace twice is idempotent.
        // Calling t() with a valid key should return the translated string.
        $result = Lang::t('recommendations.title');
        self::assertSame('For You', $result);
    }

    public function testRecommendationsScreenKeysExist(): void
    {
        // Verify all RecommendationsScreen translation keys are accessible.
        self::assertSame('For You', Lang::t('recommendations.title'));
        self::assertSame('Q: Back  ↑↓: Navigate  Enter: Open  X: Dismiss', Lang::t('recommendations.hint'));
        self::assertSame('Your session expired. Please sign in again.', Lang::t('recommendations.session_expired'));
        self::assertSame('Could not load recommendations.', Lang::t('recommendations.load_failed'));
        self::assertSame('Loading recommendations…', Lang::t('recommendations.loading'));
        self::assertNotEmpty(Lang::t('recommendations.empty'));
        self::assertSame('Could not dismiss recommendation.', Lang::t('recommendations.dismiss_failed'));
    }

    public function testDetailScreenKeysExist(): void
    {
        // Verify all DetailScreen translation keys are accessible.
        self::assertSame('Your session expired. Please sign in again.', Lang::t('detail.session_expired'));
        self::assertSame('▶  This title has no playable source.', Lang::t('detail.play_notice'));
        self::assertNotEmpty(Lang::t('detail.hint'));
        self::assertNotEmpty(Lang::t('detail.container_hint'));
        self::assertNotEmpty(Lang::t('detail.loading_hint'));
        self::assertSame('Loading…', Lang::t('detail.loading'));
        self::assertSame('No synopsis available.', Lang::t('detail.no_synopsis'));
        self::assertSame('More Like This', Lang::t('detail.more_like_this'));
        self::assertSame('Cast', Lang::t('detail.cast_label'));
        self::assertSame('Directed by ', Lang::t('detail.directed_by'));
        self::assertSame('  +{count} more', Lang::t('detail.more_cast'));
        self::assertSame('season', Lang::t('detail.season'));
        self::assertSame('episode', Lang::t('detail.episode'));
        self::assertSame('item', Lang::t('detail.item'));
    }

    public function testFilterBarKeysExist(): void
    {
        // Verify all FilterBar translation keys are accessible.
        self::assertSame('(type to filter)', Lang::t('filter.search_placeholder'));
        self::assertSame('Search: ', Lang::t('filter.search_label'));
        self::assertSame('Sort: ', Lang::t('filter.sort_label'));
        self::assertSame('Order: ', Lang::t('filter.order_label'));
        self::assertSame('asc', Lang::t('filter.order_asc'));
        self::assertSame('desc', Lang::t('filter.order_desc'));
    }
}

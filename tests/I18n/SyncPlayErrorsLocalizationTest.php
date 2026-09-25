<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\I18n;

use Phlix\Console\I18n\SyncPlayErrors;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;

/**
 * Localization contract for the Wave-2 SyncPlay dotted twins.
 *
 * The server flip (see contracts src/errors.ts SYNCPLAY_ERROR_CODE_TWINS)
 * sends syncplay.create_failed / syncplay.join_failed / syncplay.leave_failed
 * where it used to send CREATE_FAILED / JOIN_FAILED / LEAVE_FAILED. Each
 * dotted code must resolve through the real T/Lang path to exactly the text
 * its SCREAMING twin already renders — the flip is a rendering non-event.
 *
 * The four inner-path specializations (syncplay.group_limit_reached /
 * group_not_found / invalid_password / group_full, live since srv #793 at
 * SyncPlayManager.php:621/:702/:741/:745) are the opposite shape: they
 * un-wrap a coarse carrier into a precise reason, so each must resolve to
 * its OWN catalog line and never to the carrier text nor the server prose
 * they used to leak through the debug fallback.
 *
 * Removing any mapping from SyncPlayErrors::CODE_KEYS reddens its test:
 * the raw server prose would surface instead of the catalog line.
 */
final class SyncPlayErrorsLocalizationTest extends TestCase
{
    /**
     * Twin pairs pinned byte-for-byte to the contracts registry:
     * wire code => localized text per locale, dotted twin beside its carrier.
     *
     * @var array<string, array{carrier: string, twin: string, texts: array<string, string>}>
     */
    private const PAIRS = [
        'create' => [
            'carrier' => 'CREATE_FAILED',
            'twin' => 'syncplay.create_failed',
            'texts' => [
                'en' => 'Could not create the watch group.',
                'es' => 'No se pudo crear el grupo de visionado.',
                'ja' => 'ウォッチグループを作成できませんでした。',
            ],
        ],
        'join' => [
            'carrier' => 'JOIN_FAILED',
            'twin' => 'syncplay.join_failed',
            'texts' => [
                'en' => 'Could not join the watch group.',
                'es' => 'No se pudo unir al grupo de visionado.',
                'ja' => 'ウォッチグループに参加できませんでした。',
            ],
        ],
        'leave' => [
            'carrier' => 'LEAVE_FAILED',
            'twin' => 'syncplay.leave_failed',
            'texts' => [
                'en' => 'Could not leave the watch group.',
                'es' => 'No se pudo salir del grupo de visionado.',
                'ja' => 'ウォッチグループから退出できませんでした。',
            ],
        ],
    ];

    /**
     * Inner-path specializations (contracts registry, all four LIVE since
     * srv #793): dotted codes the createGroup/joinGroup handlers un-wrap
     * out of the coarse carriers. Unlike the `_failed` trio they render
     * their OWN precise catalog line, not the carrier text — before the
     * mapping they were unknown codes and surfaced the server's English
     * prose (quoted per site at srv SyncPlayManager.php:621/:702/:741/:745)
     * through the debug fallback. Texts pinned byte-for-locale to the
     * roku catalogs; carrier names the twin map routes each under.
     *
     * @var array<string, array{code: string, carrier: string, prose: string, texts: array<string, string>}>
     */
    private const SPECIALIZATIONS = [
        'group_limit_reached' => [
            'code' => 'syncplay.group_limit_reached',
            'carrier' => 'CREATE_FAILED',
            'prose' => 'Maximum group limit reached',
            'texts' => [
                'en' => 'The host has reached the limit for watch groups.',
                'es' => 'El anfitrión alcanzó el límite de salas de visionado.',
                'ja' => 'ホストのウォッチパーティが上限に達しました。',
            ],
        ],
        'group_not_found' => [
            'code' => 'syncplay.group_not_found',
            'carrier' => 'JOIN_FAILED',
            'prose' => 'Group not found',
            'texts' => [
                'en' => 'That watch group no longer exists.',
                'es' => 'Ese grupo de visionado ya no existe.',
                'ja' => 'そのウォッチグループは存在しません。',
            ],
        ],
        'invalid_password' => [
            'code' => 'syncplay.invalid_password',
            'carrier' => 'JOIN_FAILED',
            'prose' => 'Invalid password',
            'texts' => [
                'en' => 'That password is not correct.',
                'es' => 'La contraseña no es correcta.',
                'ja' => 'パスワードが正しくありません。',
            ],
        ],
        'group_full' => [
            'code' => 'syncplay.group_full',
            'carrier' => 'JOIN_FAILED',
            'prose' => 'Group is full',
            'texts' => [
                'en' => 'That watch group is full.',
                'es' => 'Ese grupo de visionado está lleno.',
                'ja' => 'そのウォッチグループは満員です。',
            ],
        ],
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

    public function testDottedTwinsResolveToCatalogTextInEnglish(): void
    {
        T::setLocale('en');

        foreach (self::PAIRS as $failure => $pair) {
            self::assertSame(
                $pair['texts']['en'],
                SyncPlayErrors::localize($pair['twin'], "unlocalized server prose for $failure"),
                "dotted twin {$pair['twin']} must render its catalog line, not the server prose",
            );
        }
    }

    public function testDottedTwinRendersByteIdenticalToItsCarrier(): void
    {
        T::setLocale('en');

        foreach (self::PAIRS as $failure => $pair) {
            self::assertSame(
                SyncPlayErrors::localize($pair['carrier'], "legacy prose for $failure"),
                SyncPlayErrors::localize($pair['twin'], "dotted prose for $failure"),
                "twin {$pair['twin']} must render exactly what {$pair['carrier']} renders today",
            );
        }
    }

    public function testDottedTwinsLocalizeInSpanish(): void
    {
        T::setLocale('es');

        foreach (self::PAIRS as $pair) {
            self::assertSame($pair['texts']['es'], SyncPlayErrors::localize($pair['twin'], ''));
        }
    }

    public function testDottedTwinsLocalizeInJapanese(): void
    {
        T::setLocale('ja');

        foreach (self::PAIRS as $pair) {
            self::assertSame($pair['texts']['ja'], SyncPlayErrors::localize($pair['twin'], ''));
        }
    }

    public function testInnerPathSpecializationsResolveToOwnCatalogLines(): void
    {
        T::setLocale('en');

        foreach (self::SPECIALIZATIONS as $name => $spec) {
            self::assertSame(
                $spec['texts']['en'],
                SyncPlayErrors::localize($spec['code'], $spec['prose']),
                "specialization {$spec['code']} must render its catalog line, not the server prose '$spec[prose]'",
            );
        }
    }

    public function testInnerPathSpecializationsAreMorePreciseThanTheirCarriers(): void
    {
        T::setLocale('en');

        foreach (self::SPECIALIZATIONS as $name => $spec) {
            self::assertNotSame(
                SyncPlayErrors::localize($spec['carrier'], "legacy prose for $name"),
                SyncPlayErrors::localize($spec['code'], $spec['prose']),
                "specialization {$spec['code']} must un-wrap its carrier '$spec[carrier]' into its own line",
            );
        }
    }

    public function testInnerPathSpecializationsLocalizeInSpanish(): void
    {
        T::setLocale('es');

        foreach (self::SPECIALIZATIONS as $spec) {
            self::assertSame($spec['texts']['es'], SyncPlayErrors::localize($spec['code'], ''));
        }
    }

    public function testInnerPathSpecializationsLocalizeInJapanese(): void
    {
        T::setLocale('ja');

        foreach (self::SPECIALIZATIONS as $spec) {
            self::assertSame($spec['texts']['ja'], SyncPlayErrors::localize($spec['code'], ''));
        }
    }

    public function testUnknownCodeStillFallsBackToServerMessage(): void
    {
        T::setLocale('en');

        self::assertSame(
            'A code from a future server',
            SyncPlayErrors::localize('syncplay.future_specialization', 'A code from a future server'),
        );
    }

    public function testUnknownCodeWithoutMessageFallsBackToGenericLine(): void
    {
        T::setLocale('en');

        self::assertSame('A sync error occurred.', SyncPlayErrors::localize('syncplay.future_specialization', ''));
    }
}

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
 * Removing any twin mapping from SyncPlayErrors::CODE_KEYS reddens the
 * twin tests: the raw server prose would surface instead of the catalog line.
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

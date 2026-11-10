<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api;

use Phlix\Console\I18n\SyncPlayErrors;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use SugarCraft\Core\I18n\T;

/**
 * LW-090-b console error-code registry gate (wiring-clients I4 sibling).
 *
 * WHAT IT PINS: the console's SyncPlay wire-code map (`SyncPlayErrors::
 * CODE_KEYS`) is exactly the contracts registry's syncplay jurisdiction —
 * byte-anchored through a vendored copy of `@phlix/contracts`
 * `dist/error-codes.json` (`tests/fixtures/contracts-error-codes.json`,
 * the v0.5.7 artifact, registry UNMOVED through v0.5.7 so this is the
 * first and only vendor: zero re-vendor owed). Beside the route-manifest
 * gate (S405, same directory), the error-code half of the cascade trio
 * had NO vendored pin on console: before this commit a registry move
 * (added/retired syncplay code) would silently rot the localize() map.
 *
 * Laws, each reddening on its own drift class:
 * 1. INTEGRITY — the vendored artifact is byte-identical to the shipped
 *    contracts build (md5 of the v0.5.7 registry). Mutating any byte of
 *    the fixture reddens here; trio moves are OWNER-gated estate law.
 * 2. EQUALITY — the registry's `syncplay.*` family and the console's
 *    dotted CODE_KEYS are the SAME SET. A registry addition (new
 *    specialization the console must map) and a console-only dotted key
 *    (a code retired upstream) both redden; neither side may drift alone.
 * 3. CONTAINMENT — every SCREAMING carrier the console maps is a real
 *    registry code. Carriers are server literals the registry also
 *    declares (NOT_AUTHENTICATED … UNKNOWN_MESSAGE); inventing a
 *    screaming mapping for a non-registry code reddens here.
 * 4. BEHAVIOR — every console-mapped wire code resolves through the real
 *    localize() path to a catalog line: never the debug-prose fallback,
 *    never empty, never the raw key render an absent catalog line
 *    produces ("phlix.*"). Dropping a CODE_KEYS row reddens law 2;
 *    dropping its catalog line reddens here.
 * 5. NON-VACUITY — a planted code that is in neither the registry nor
 *    the map must fall back to the server prose, proving law 4's
 *    "never falls back" is a conviction and not a tautology.
 */
final class ContractsErrorCodesGateTest extends TestCase
{
    private const GATE_ID = 'lw090b-console-error-codes-gate-v1';

    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/contracts-error-codes.json';

    /** Byte identity of @phlix/contracts dist/error-codes.json at tag v0.5.7 (trio HELD). */
    private const EXPECTED_MD5 = '5d1783a71a3c4e128b2021327cad3717';

    /** Registry code count measured in the v0.5.7 artifact (205-code era). */
    private const EXPECTED_CODE_COUNT = 205;

    /** Generator provenance named in the artifact's $comment. */
    private const EXPECTED_GENERATOR = 'scripts/emit-error-codes.mjs';

    /** A code that must exist in no law's crosshairs: the planted probe. */
    private const PLANTED_CODE = 'syncplay.planted_probe_x9';

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

    // ── law 1: integrity ───────────────────────────────────────────────

    public function testVendoredRegistryIsTheContractsArtifactByteIdentical(): void
    {
        $raw = (string) file_get_contents(self::FIXTURE_PATH);
        self::assertSame(
            self::EXPECTED_MD5,
            md5($raw),
            self::GATE_ID . ': vendored error-codes.json drifted from the @phlix/contracts v0.5.7 registry'
        );

        $decoded = self::registry();
        self::assertStringContainsString(self::EXPECTED_GENERATOR, $decoded['$comment']);
        self::assertCount(self::EXPECTED_CODE_COUNT, $decoded['codes']);

        $unique = array_unique($decoded['codes']);
        self::assertCount(self::EXPECTED_CODE_COUNT, $unique, 'registry codes must be pair-unique');
        self::assertGreaterThanOrEqual(1, self::syncplayFamily(), 'law 2 is vacuous without a syncplay family');
    }

    // ── law 2: set equality of the syncplay jurisdiction ───────────────

    public function testRegistrySyncplayFamilyEqualsTheMappedDottedCodes(): void
    {
        $registry = self::syncplayFamily();
        $mapped = self::mappedDottedCodes();

        self::assertSame(
            $registry,
            $mapped,
            self::GATE_ID . ': the console dotted wire-code set no longer equals the contracts'
            . ' syncplay.* family — missing: [' . implode(', ', array_diff($registry, $mapped))
            . '] extra: [' . implode(', ', array_diff($mapped, $registry)) . ']'
        );
    }

    // ── law 3: containment of the SCREAMING carriers ───────────────────

    public function testEveryMappedScreamingCarrierIsARegistryCode(): void
    {
        $registry = self::registry()['codes'];
        $foreign = [];
        foreach (self::mappedScreamingCodes() as $carrier) {
            if (!in_array($carrier, $registry, true)) {
                $foreign[] = $carrier;
            }
        }

        self::assertSame(
            [],
            $foreign,
            self::GATE_ID . ': SyncPlayErrors maps SCREAMING carriers the registry does not declare: '
            . implode(', ', $foreign)
        );
    }

    // ── law 4: every mapped code resolves to its catalog line ──────────

    public function testEveryMappedWireCodeResolvesToACatalogLine(): void
    {
        T::setLocale('en');

        $leaks = [];
        foreach (self::mappedWireCodes() as $code) {
            $prose = 'debug prose for ' . $code;
            $text = SyncPlayErrors::localize($code, $prose);
            if ($text === $prose || $text === '' || str_starts_with($text, 'phlix.')) {
                $leaks[] = $code;
            }
        }

        self::assertSame(
            [],
            $leaks,
            self::GATE_ID . ': mapped wire codes did not render a real catalog line (missing CODE_KEYS'
            . ' row leaked prose; missing catalog key leaked the raw "phlix.*" render): ' . implode(', ', $leaks)
        );
    }

    // ── law 5: non-vacuity ─────────────────────────────────────────────

    public function testPlantedUnknownCodeStillFallsBackToServerProse(): void
    {
        T::setLocale('en');

        self::assertFalse(in_array(self::PLANTED_CODE, self::registry()['codes'], true));
        self::assertNotContains(self::PLANTED_CODE, self::mappedWireCodes());
        self::assertSame(
            'planted prose must survive as the debug fallback',
            SyncPlayErrors::localize(self::PLANTED_CODE, 'planted prose must survive as the debug fallback'),
            self::GATE_ID . ': the planted probe resolved — localize() is not discriminating'
        );
    }

    // ── machinery ──────────────────────────────────────────────────────

    /**
     * @return array{'$comment': string, codes: list<string>}
     */
    private static function registry(): array
    {
        if (!is_file(self::FIXTURE_PATH)) {
            throw new RuntimeException('Vendored registry missing: ' . self::FIXTURE_PATH);
        }
        /** @var array{'$comment': string, codes: list<string>} $decoded */
        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    /** @return list<string> registry codes in the syncplay.* family, sorted. */
    private static function syncplayFamily(): array
    {
        $family = array_values(array_filter(
            self::registry()['codes'],
            static fn (string $code): bool => str_starts_with($code, 'syncplay.')
        ));
        sort($family);
        return $family;
    }

    /** @return array<string, string> wire code => catalog key (private const, by law here). */
    private static function codeKeys(): array
    {
        $reflection = new ReflectionClass(SyncPlayErrors::class);
        $keys = $reflection->getConstant('CODE_KEYS');
        if (!is_array($keys)) {
            throw new RuntimeException(self::GATE_ID . ': SyncPlayErrors::CODE_KEYS is gone or not an array.');
        }
        /** @var array<string, string> $keys */
        return $keys;
    }

    /** @return list<string> dotted (lowercase) wire codes the console maps, sorted. */
    private static function mappedDottedCodes(): array
    {
        $dotted = array_values(array_filter(
            array_keys(self::codeKeys()),
            static fn (string $code): bool => str_contains($code, '.')
        ));
        sort($dotted);
        return $dotted;
    }

    /** @return list<string> SCREAMING carriers the console maps, sorted. */
    private static function mappedScreamingCodes(): array
    {
        $screaming = array_values(array_filter(
            array_keys(self::codeKeys()),
            static fn (string $code): bool => !str_contains($code, '.')
        ));
        sort($screaming);
        return $screaming;
    }

    /** @return list<string> every wire code the console maps, sorted. */
    private static function mappedWireCodes(): array
    {
        $all = array_keys(self::codeKeys());
        sort($all);
        return $all;
    }
}

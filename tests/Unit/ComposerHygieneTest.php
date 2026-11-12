<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * composer.json declares the truth it ships on [review console__devops #4,
 * #6 / console__srphp #6 / CU-044]:
 *
 *  - the extensions the runtime paths depend on are REQUIRED, not ambient:
 *    `json_decode`/`json_encode` pervade the API layer — and ext-json has
 *    been CORE (always enabled, not disable-able) since PHP 8.0, so its
 *    declaration is redundant-by-design documentation of that codec
 *    pervasiveness, not a capability gate [forward correction of the
 *    "became a disable-able package in 8.4" rationale first shipped with
 *    CU-044 — see the dated CHANGELOG correction entry], while the
 *    SIGINT/TERM teardown that promises "don't orphan ffplay/ffmpeg
 *    children" is the pcntl_signal family (App.php). ext-pcntl is
 *    composer-enforced (soft only in PHAR) [CON-S13 / CON-14]: the hard
 *    require stays, every requirements doc site says exactly that, and the
 *    function_exists() guards only exist so a PHAR — which skips composer's
 *    platform check — degrades instead of fataling (pinned in
 *    RuntimeRequirementsDocsTest, whose census now reads this file's
 *    require block too). ext-curl stays deliberately undeclared — its
 *    doctor probe is loud by design.
 *  - the polyfill `provide` stays EXPLICIT and paired with the ceiling:
 *    humbug/box (dev-only) declares `replace: symfony/polyfill-php8x *`, so
 *    dropping the root provide lets box satisfy league/commonmark's prod
 *    requirement and promotes box's entire dev tooling tree into the
 *    production install closure — measured, not theorized (both the silent
 *    removal and a real-polyfill require moved 55 packages dev→prod in the
 *    lock; the idiomatic `conflict` variant left the graph unsatisfiable).
 *    On php ^8.3 every polyfilled symbol is core truth, so the claim is
 *    sound; the lock-level guard below pins the box tree where it belongs.
 *  - the PHP constraint is CEILINGED (^8.3): the tested family is 8.3/8.4/
 *    8.5 against a dev-master dependency chain whose reproducibility rests
 *    on the lock; an unpinned future major would satisfy a bare '>=8.3'.
 *  - every CI job that runs `composer install` declares the extensions the
 *    require set now enforces — a missing ext fails the install, not the
 *    runtime.
 */
final class ComposerHygieneTest extends TestCase
{
    public static function testTheDeclaredRuntimeExtensionsAreRequired(): void
    {
        $require = self::composerJson()['require'] ?? null;
        self::assertIsArray($require);

        foreach (['ext-json', 'ext-pcntl'] as $extension) {
            self::assertArrayHasKey(
                $extension,
                $require,
                "{$extension} is on a runtime path and must be declared, not ambient "
                . '(ext-pcntl: composer-enforced, soft only in PHAR — dropping it means rotating '
                . 'RuntimeRequirementsDocsTest and all five requirements sites, never silently)'
            );
            self::assertSame('*', $require[$extension]);
        }
    }

    public static function testThePolyfillProvideIsTheExactLoadBearingClaim(): void
    {
        $provide = self::composerJson()['provide'] ?? null;
        self::assertIsArray(
            $provide,
            'the root provide is not decoration: without it, humbug/box\'s dev-only replace satisfies '
            . 'league/commonmark and box\'s tooling tree is promoted into the production closure'
        );
        self::assertSame(
            ['symfony/polyfill-php80', 'symfony/polyfill-php81', 'symfony/polyfill-php82'],
            array_keys($provide),
            'the claim covers exactly the php8x polyfills and nothing else'
        );
    }

    public static function testTheBoxToolingStaysOutOfTheProductionClosure(): void
    {
        $lock = self::composerLock();
        $prodNames = array_map(static fn (array $package): string => (string) $package['name'], $lock['packages']);
        $devNames = array_map(
            static fn (array $package): string => (string) $package['name'],
            $lock['packages-dev'] ?? []
        );

        self::assertNotContains('humbug/box', $prodNames, 'box in packages = the provide was dropped or outvoted');
        self::assertContains('humbug/box', $devNames, 'the phar build needs box; dev-closure is its home');
    }

    public static function testThePhpConstraintIsCeiled(): void
    {
        $require = self::composerJson()['require'] ?? null;
        self::assertIsArray($require);
        self::assertSame(
            '^8.3',
            $require['php'] ?? null,
            'a bare >=8.3 admits untested majors against a dev-master chain; ^8.3 ceilings at <9.0'
        );
    }

    public static function testEveryInstallingCiJobDeclaresPcntl(): void
    {
        $workflows = glob(dirname(__DIR__, 2) . '/.github/workflows/*.yml');
        self::assertIsArray($workflows);
        self::assertGreaterThan(0, count($workflows), 'no workflows to audit — wrong venue');

        foreach ($workflows as $path) {
            $lines = file($path);
            self::assertIsArray($lines);
            foreach ($lines as $number => $line) {
                $trimmed = trim($line);
                if (!str_starts_with($trimmed, 'extensions:')) {
                    continue;
                }
                $declared = array_map('trim', explode(',', substr($trimmed, strlen('extensions:'))));
                self::assertContains(
                    'pcntl',
                    $declared,
                    basename($path) . ':' . ($number + 1) . ' sets up PHP for a job that runs composer install; '
                    . 'composer.json requires ext-pcntl (composer-enforced; soft only in PHAR), '
                    . 'so an install here without it fails'
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function composerJson(): array
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        self::assertIsString($raw, 'composer.json unreadable — wrong venue');
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'composer.json is not valid JSON');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array{packages: array<int, array<string, mixed>>, packages-dev?: array<int, array<string, mixed>>}
     */
    private static function composerLock(): array
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/composer.lock');
        self::assertIsString($raw, 'composer.lock unreadable — wrong venue');
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'composer.lock is not valid JSON');
        self::assertArrayHasKey('packages', $decoded, 'composer.lock has no production package list');

        /** @var array{packages: array<int, array<string, mixed>>, packages-dev?: array<int, array<string, mixed>>} $decoded */
        return $decoded;
    }
}

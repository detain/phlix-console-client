<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Guard the w06/L1 newcomer fixes (reviews console__newcomer #1/#2/#3,
 * ledger CU-061/062/063):
 *
 * (1) The README used to open with a single ~15-line paragraph that dumped
 *     every subsystem and terminal-graphics protocol name at a reader who has
 *     not seen the product yet. It is now a short plain-language definition
 *     followed by a four-bullet feature list — and the "you need a running
 *     server first" caveat sits in the opening, not 50 lines down.
 * (2) docs/upgrade/v1.md spoke of migrating "from pre-1.0 versions" without
 *     saying that no 0.x release was ever published — origin has never carried
 *     a version tag; the only artifact ever shipped is the rolling prerelease
 *     PHAR under `latest`. The guide now states that reality up front.
 * (3) The two policy documents (vendor-patch policy, dependency policy) were
 *     reachable from no navigation surface; README's "Further reading" now
 *     links both.
 *
 * These pins are presence/absence on exact strings. They deliberately do NOT
 * re-impose the paragraph structure beyond what the fixes established.
 */
final class NewcomerClaimsDocsTest extends TestCase
{
    private const README = __DIR__ . '/../../README.md';
    private const UPGRADE_V1 = __DIR__ . '/../../docs/upgrade/v1.md';

    public function testReadmeOpensWithShortDefinitionNotJargonWall(): void
    {
        $readme = self::read(self::README);

        // The opening paragraph: from the bold definition to the first blank
        // line after the badges. It must be a definition, not an inventory —
        // pinned by length (the pre-fix wall was ~1,100 characters).
        $start = strpos($readme, '**Phlix is an open-source media server');
        self::assertIsInt($start, 'README must open its body with the plain definition sentence');
        $end = strpos($readme, "\n\n", $start);
        self::assertIsInt($end);
        $opening = substr($readme, $start, $end - $start);
        self::assertLessThan(
            600,
            strlen($opening),
            'The opening paragraph must stay a short plain-language definition; '
            . 'feature inventory belongs in the bullets below it.'
        );

        // The feature bullets that replaced the wall.
        foreach (
            ['- **Browse**', '- **Watch together**',
             '- **Play in the terminal**', '- **Stay in control**'] as $bullet
        ) {
            self::assertStringContainsString(
                $bullet,
                $readme,
                "The README intro must keep its '$bullet' feature bullet (w06/L1 restructure)."
            );
        }
    }

    public function testReadmeIntroCarriesTheServerCaveat(): void
    {
        $readme = self::read(self::README);
        $introStart = strpos($readme, '**Phlix is an open-source media server');
        $introEnd = strpos($readme, "\n## Documentation", (int) $introStart);
        self::assertIsInt($introEnd, 'README intro must end at the Documentation section');
        $intro = substr($readme, (int) $introStart, $introEnd - (int) $introStart);

        self::assertMatchesRegularExpression(
            '/you need one running before this client/i',
            $intro,
            'The intro must tell a newcomer that a running Phlix server is a prerequisite, '
            . 'not leave it buried in Requirements.'
        );
    }

    public function testUpgradeGuideDisclosesPrereleaseReality(): void
    {
        $guide = self::read(self::UPGRADE_V1);

        self::assertMatchesRegularExpression(
            '/never (shipped|cut)/',
            $guide,
            'The upgrade guide must say plainly that no 0.x series was ever shipped.'
        );
        self::assertStringContainsString(
            'rolling prerelease',
            $guide,
            'The guide must name the rolling prerelease PHAR as the only published artifact.'
        );
        self::assertStringContainsString(
            '../../README.md#phar-end-user',
            $guide,
            'The guide should point readers at the README section that describes the artifact.'
        );
    }

    public function testFurtherReadingLinksThePolicyDocs(): void
    {
        $readme = self::read(self::README);

        self::assertStringContainsString(
            '](docs/vendor-patches.md)',
            $readme,
            'The vendor-patch policy must be reachable from README navigation (review console__newcomer #3).'
        );
        self::assertStringContainsString(
            '](docs/dev/dependencies.md)',
            $readme,
            'The dependency policy must be reachable from README navigation (review console__newcomer #3).'
        );
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read $path");
        }
        return $contents;
    }
}

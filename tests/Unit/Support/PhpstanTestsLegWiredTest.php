<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S448 wiring gate — phpstan-tests.neon must be INVOKED by CI, not just committed.
 *
 * The config file existed in the tree with zero CI jobs reading it: a gate that
 * exists only in appearance (the mirror-image of the server/hub two-leg pattern).
 * S448 fixed its 9 findings and wired a `Run PHPStan (tests)` step into BOTH the
 * ci.yml `phpstan` job and the deps.yml `update` job.
 *
 * THIS TEST PINS THAT WIRING, NOT THE CONFIG. It parses the two workflow files as
 * text and asserts each contains the `-c phpstan-tests.neon` invocation. Delete
 * either CI step and this test goes RED — the exact regression the step exists to
 * prevent (an unwired config silently rotting back into an invisible gate). It
 * deliberately does NOT shell out to phpstan: this is a wiring assertion, cheap
 * and deterministic, and the real analysis gate is the CI leg itself.
 */
final class PhpstanTestsLegWiredTest extends TestCase
{
    /** Code-resident survival token for the S448 lane. */
    private const SURVIVAL_TOKEN = 'S448PHPSTANX5T8';

    /**
     * Every workflow file that MUST invoke the tests leg, with the job that owns
     * the step. Both are load-bearing: ci.yml is the PR/master gate, deps.yml is
     * the scheduled lock-refresh gate — a finding must not survive either path.
     */
    private const REQUIRED_LEGS = [
        '.github/workflows/ci.yml' => 'phpstan',
        '.github/workflows/deps.yml' => 'update',
    ];

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function legProvider(): array
    {
        $cases = [];
        foreach (self::REQUIRED_LEGS as $workflow => $job) {
            $cases[$workflow] = [$workflow, $job];
        }

        return $cases;
    }

    /**
     * @param non-empty-string $workflow
     * @param non-empty-string $job
     *
     * @dataProvider legProvider
     */
    public function testWorkflowInvokesPhpstanTestsConfig(string $workflow, string $job): void
    {
        $path = $this->repoRoot() . '/' . $workflow;
        self::assertFileExists($path, self::SURVIVAL_TOKEN . ": missing workflow {$workflow}");

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString(
            'phpstan-tests.neon',
            $contents,
            self::SURVIVAL_TOKEN . ": {$workflow} must analyse tests/ via '-c phpstan-tests.neon' "
                . "— the phpstan job '{$job}' must not drop the second leg (unwired config = invisible gate)"
        );
        self::assertStringContainsString(
            'vendor/bin/phpstan analyse -c phpstan-tests.neon',
            $contents,
            self::SURVIVAL_TOKEN . ": {$workflow} must run the tests leg with the canonical phpstan invocation"
        );
    }

    public function testTheAnalysedConfigFileIsCommitted(): void
    {
        $config = $this->repoRoot() . '/phpstan-tests.neon';
        self::assertFileExists($config, self::SURVIVAL_TOKEN . ': phpstan-tests.neon must stay in the tree');

        $contents = (string) file_get_contents($config);
        self::assertStringContainsString(
            'paths:',
            $contents,
            self::SURVIVAL_TOKEN . ': phpstan-tests.neon must declare an analysis scope'
        );
    }
}

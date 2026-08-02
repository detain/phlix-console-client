<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the bin/phlix CLI entry point.
 *
 * Verifies:
 * - `help` exits 0 and prints help text
 * - `bogus-command` exits 2 and prints error to STDERR
 * - `--version` exits 0 and prints "phlix <version>"
 * - `version` exits 0 and prints "phlix <version>"
 */
final class BinPhlixTest extends TestCase
{
    private string $binPath;

    protected function setUp(): void
    {
        $this->binPath = __DIR__ . '/../bin/phlix';
    }

    public function testHelpExitsZero(): void
    {
        $output = $this->runPhlix(['help']);
        $this->assertSame(0, $output['exitCode'], 'help should exit with code 0');
    }

    public function testHelpPrintsUsage(): void
    {
        $output = $this->runPhlix(['help']);
        $this->assertStringContainsString('phlix — Phlix console client', $output['stdout']);
        $this->assertStringContainsString('phlix doctor', $output['stdout']);
        $this->assertStringContainsString('phlix poster', $output['stdout']);
        $this->assertStringContainsString('phlix frame', $output['stdout']);
        $this->assertStringContainsString('phlix run', $output['stdout']);
        $this->assertStringContainsString('phlix version', $output['stdout']);
        $this->assertStringContainsString('phlix help', $output['stdout']);
    }

    public function testNoArgumentExitsZeroAndPrintsHelp(): void
    {
        $output = $this->runPhlix([]);
        $this->assertSame(0, $output['exitCode'], 'no argument should exit with code 0');
        $this->assertStringContainsString('phlix — Phlix console client', $output['stdout']);
    }

    public function testUnknownCommandExitsTwo(): void
    {
        $output = $this->runPhlix(['bogus-command']);
        $this->assertSame(2, $output['exitCode'], 'unknown command should exit with code 2');
    }

    public function testUnknownCommandPrintsErrorToStderr(): void
    {
        $output = $this->runPhlix(['bogus-command']);
        $this->assertStringContainsString('Unknown command: bogus-command', $output['stderr']);
    }

    public function testUnknownCommandPrintsHelpToStderr(): void
    {
        $output = $this->runPhlix(['bogus-command']);
        $this->assertStringContainsString('phlix — Phlix console client', $output['stderr']);
    }

    public function testVersionFlagExitsZero(): void
    {
        $output = $this->runPhlix(['--version']);
        $this->assertSame(0, $output['exitCode'], '--version should exit with code 0');
    }

    public function testVersionFlagPrintsOneLine(): void
    {
        $output = $this->runPhlix(['--version']);
        $this->assertMatchesRegularExpression('/^phlix .+$/', $output['stdout']);
    }

    public function testVersionFlagMatchesExpectedPattern(): void
    {
        $output = $this->runPhlix(['--version']);
        $this->assertMatchesRegularExpression('/^phlix .+$/', $output['stdout']);
    }

    public function testVersionShortFlagExitsZero(): void
    {
        $output = $this->runPhlix(['-V']);
        $this->assertSame(0, $output['exitCode'], '-V should exit with code 0');
    }

    public function testVersionShortFlagPrintsOneLine(): void
    {
        $output = $this->runPhlix(['-V']);
        $this->assertMatchesRegularExpression('/^phlix .+$/', $output['stdout']);
    }

    public function testVersionCommandExitsZero(): void
    {
        $output = $this->runPhlix(['version']);
        $this->assertSame(0, $output['exitCode'], 'version should exit with code 0');
    }

    public function testVersionCommandPrintsOneLine(): void
    {
        $output = $this->runPhlix(['version']);
        $this->assertMatchesRegularExpression('/^phlix .+$/', $output['stdout']);
    }

    /**
     * Run the phlix binary with given arguments and capture output.
     *
     * @param array<string> $args
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runPhlix(array $args): array
    {
        $binPath = $this->binPath;
        $command = array_merge([$binPath], $args);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to open process');
        }

        // Close stdin
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}

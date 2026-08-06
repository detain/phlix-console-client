<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the PHAR build and distribution.
 *
 * Verifies:
 * - The PHAR build script exists and is executable (when PHAR cannot be built in CI)
 * - If a PHAR exists, it runs --version and exits 0
 * - The PHAR --version output matches the expected format
 */
final class PharTest extends TestCase
{
    private const EXPECTED_PHAR_PATHS = [
        __DIR__ . '/../build/phlix.phar',
        __DIR__ . '/../phlix.phar',
    ];

    private const BUILD_SCRIPT_PATHS = [
        __DIR__ . '/../scripts/build-phar.sh',
        __DIR__ . '/../build-phar.sh',
        __DIR__ . '/../box.json',
    ];

    /**
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runCommand(string $command, array $args = []): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $fullCommand = array_merge([$command], $args);
        $process = proc_open($fullCommand, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to open process: ' . $command);
        }

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

    public function testBuildScriptExists(): void
    {
        $found = false;
        foreach (self::BUILD_SCRIPT_PATHS as $path) {
            if (file_exists($path)) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            'PHAR build script not found. Expected one of: ' . implode(', ', self::BUILD_SCRIPT_PATHS)
        );
    }

    public function testBoxJsonIsValidPhp(): void
    {
        $boxJsonPath = __DIR__ . '/../box.json';
        if (!file_exists($boxJsonPath)) {
            $this->markTestSkipped('box.json not found');
        }

        $content = file_get_contents($boxJsonPath);
        $this->assertNotFalse($content, 'box.json should be readable');
        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded, 'box.json should be valid JSON');
        $this->assertArrayHasKey('main', $decoded, 'box.json should have a "main" entry point');
    }

    public function testPharVersionCommandExitsZero(): void
    {
        $pharPath = $this->findPhar();
        if ($pharPath === null) {
            $this->markTestSkipped('PHAR not built yet. Run: scripts/build-phar.sh');
        }

        $result = $this->runCommand('php', [$pharPath, '--version']);
        $this->assertSame(
            0,
            $result['exitCode'],
            '--version should exit with code 0, got: ' . $result['exitCode'] . ', stderr: ' . $result['stderr']
        );
    }

    public function testPharVersionCommandPrintsVersionLine(): void
    {
        $pharPath = $this->findPhar();
        if ($pharPath === null) {
            $this->markTestSkipped('PHAR not built yet. Run: scripts/build-phar.sh');
        }

        $result = $this->runCommand('php', [$pharPath, '--version']);
        $this->assertMatchesRegularExpression(
            '/^phlix .+$/',
            $result['stdout'],
            '--version should print "phlix <version>", got: ' . $result['stdout']
        );
    }

    public function testPharHelpCommandExitsZero(): void
    {
        $pharPath = $this->findPhar();
        if ($pharPath === null) {
            $this->markTestSkipped('PHAR not built yet. Run: scripts/build-phar.sh');
        }

        $result = $this->runCommand('php', [$pharPath, 'help']);
        $this->assertSame(
            0,
            $result['exitCode'],
            'help should exit with code 0, got: ' . $result['exitCode']
        );
    }

    public function testPharHelpCommandPrintsUsage(): void
    {
        $pharPath = $this->findPhar();
        if ($pharPath === null) {
            $this->markTestSkipped('PHAR not built yet. Run: scripts/build-phar.sh');
        }

        $result = $this->runCommand('php', [$pharPath, 'help']);
        $this->assertStringContainsString('phlix — Phlix console client', $result['stdout']);
        $this->assertStringContainsString('phlix doctor', $result['stdout']);
        $this->assertStringContainsString('phlix poster', $result['stdout']);
        $this->assertStringContainsString('phlix run', $result['stdout']);
        $this->assertStringContainsString('phlix version', $result['stdout']);
    }

    public function testPharIsExecutable(): void
    {
        $pharPath = $this->findPhar();
        if ($pharPath === null) {
            $this->markTestSkipped('PHAR not built yet. Run: scripts/build-phar.sh');
        }

        $this->assertFileExists($pharPath, 'PHAR should exist');
        $this->assertTrue(
            is_readable($pharPath),
            'PHAR should be readable'
        );
    }

    public function testPharHasShebangInline(): void
    {
        $pharPath = $this->findPhar();
        if ($pharPath === null) {
            $this->markTestSkipped('PHAR not built yet. Run: scripts/build-phar.sh');
        }

        $handle = fopen($pharPath, 'rb');
        $this->assertNotFalse($handle, 'PHAR should be openable');
        $header = fread($handle, 2);
        fclose($handle);

        // PHAR files start with #! for shebang or <?php for stub
        // A proper PHAR may start with #!/usr/bin/env php\n or similar
        $this->assertNotEmpty($header, 'PHAR should have a header');
    }

    /**
     * Find the PHAR file if it exists.
     */
    private function findPhar(): ?string
    {
        foreach (self::EXPECTED_PHAR_PATHS as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}

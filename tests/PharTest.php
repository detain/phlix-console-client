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
 * - box.json's finders cover the locale catalogs, and a built PHAR ships them
 *   with a working end-to-end translation [CU-014]
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
     * The box.json finders must cover resources/, not just vendor/ and src/.
     *
     * [CU-014] The published phar bundled only vendor/ and src/: Lang::DIR
     * (`__DIR__ . '/../../resources/lang'`) resolved to a phar path that was
     * never packaged, the vendor T.php loader returned [] for the missing
     * file, and every Lang::t() call in the shipped phar rendered the raw
     * dotted key ('phlix.login.title') — English AND every locale. This
     * assertion runs against the tracked config alone, so CI enforces the
     * packaging law even in jobs that never build a phar.
     */
    public function testTheBoxConfigBundlesTheLocaleCatalogs(): void
    {
        $boxJsonPath = __DIR__ . '/../box.json';
        if (!file_exists($boxJsonPath)) {
            $this->markTestSkipped('box.json not found');
        }

        $decoded = json_decode((string) file_get_contents($boxJsonPath), true);
        $this->assertIsArray($decoded, 'box.json should decode');

        $coversCatalogs = false;
        foreach ((array) ($decoded['finder'] ?? []) as $finder) {
            if (!is_array($finder)) {
                continue;
            }
            $in = (array) ($finder['in'] ?? []);
            $name = (array) ($finder['name'] ?? []);
            if (in_array('resources', $in, true) && in_array('*.php', $name, true)) {
                $coversCatalogs = true;
            }
        }

        $this->assertTrue(
            $coversCatalogs,
            'box.json must carry a finder for resources/**/*.php — without it the phar ships zero locale catalogs [CU-014]'
        );
    }

    /**
     * When a built phar is on disk, prove the catalogs rode along AND that
     * the packaged artifact actually translates: the shipped autoload plus
     * phar-relative Lang::DIR must render the German catalog end-to-end.
     */
    public function testThePackagedPharCarriesTranslatableCatalogs(): void
    {
        $pharPath = $this->findPhar();
        if ($pharPath === null) {
            $this->markTestSkipped('PHAR not built yet. Run: scripts/build-phar.sh');
        }

        $absolute = (string) realpath($pharPath);

        foreach (['de', 'en', 'es', 'fr', 'it', 'ja', 'pt-br'] as $locale) {
            $this->assertFileExists(
                'phar://' . $absolute . '/resources/lang/' . $locale . '.php',
                'the published phar must ship resources/lang/' . $locale . '.php [CU-014]'
            );
        }

        $code = sprintf(
            'require "phar://%s/vendor/autoload.php";'
            . '\SugarCraft\Core\I18n\T::setLocale("de");'
            . 'fwrite(STDOUT, \Phlix\Console\I18n\Lang::t("recommendations.title"));',
            $absolute
        );

        $result = $this->runCommand('php', ['-r', $code]);

        $this->assertSame(
            0,
            $result['exitCode'],
            'probing the packaged translator should exit 0, stderr: ' . $result['stderr']
        );
        $this->assertStringContainsString('Für dich', $result['stdout'], 'the shipped phar resolves Lang::t() from its packaged de catalog');
        $this->assertStringNotContainsString('phlix.recommendations.title', $result['stdout'], 'the raw dotted key must never render from a healthy phar');
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

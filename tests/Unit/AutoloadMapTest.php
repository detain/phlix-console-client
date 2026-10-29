<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * PSR-4 path↔namespace map guard (fix campaign C10, review console__srphp /
 * console__ipd criticals).
 *
 * Two interface files lived at src/Phlix/Console/SugarCraft/Screen/ while
 * declaring namespace Phlix\Console\SugarCraft\Screen — under the composer.json
 * mapping "Phlix\\Console\\": "src/" the autoloader looks for them at
 * src/SugarCraft/Screen/. A plain `composer install` checkout therefore fatals
 * at CLASS-LINK TIME the moment a user opens "Shared with me" or "Invite links":
 * Error: Interface "Phlix\Console\SugarCraft\Screen\Breadcrumbed" not found.
 *
 * Why no other gate sees it: phpstan resolves src/ by scanning PATHS (never via
 * the composer autoloader), and the box PHAR embeds a classmap that forgives
 * misplaced files. Only the PSR-4 fallback loader enforces the convention — and
 * only at runtime. This test makes the violation impossible to re-land:
 *
 *  1. every src/ file's path must equal the path its declared namespace maps
 *     to under the PSR-4 prefix (static, catches drift even with an optimized
 *     classmap in place), and
 *  2. every screen under src/Screen/ must actually LINK through the composer
 *     autoloader (dynamic, catches the full fatal chain: a screen whose
 *     interface parents cannot resolve goes red here, in CI, pre-merge).
 */
final class AutoloadMapTest extends TestCase
{
    /** PSR-4 prefix composer.json maps onto src/ (the authority for this check). */
    private const PSR4_PREFIX = 'Phlix\\Console\\';

    /** Repo root, derived from this file's known location (tests/Unit/). */
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string> every src/ PHP file path (relative to repo root)
     */
    private static function srcFiles(): array
    {
        $root = self::repoRoot();
        $dir = $root . '/src';
        if (!is_dir($dir)) {
            throw new RuntimeException('autoload guard expects a src/ tree at ' . $dir);
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
        sort($files);

        return $files;
    }

    /**
     * Compute the PSR-4 path a declared namespace + file basename must live at.
     */
    private static function expectedPath(string $namespace, string $relativeFile): string
    {
        if (!str_starts_with($namespace . '\\', self::PSR4_PREFIX)) {
            throw new RuntimeException(
                "src/ file $relativeFile declares namespace $namespace outside the "
                . self::PSR4_PREFIX . " PSR-4 prefix — composer.json is the authority here."
            );
        }

        $sub = substr($namespace, strlen(self::PSR4_PREFIX));
        $dir = $sub === '' ? 'src' : 'src/' . str_replace('\\', '/', $sub);

        return $dir . '/' . basename($relativeFile);
    }

    public function testTheMisplacedInterfacesNowResolveThroughTheAutoloader(): void
    {
        // The exact repro from the review: before the move both were false and
        // linking either consumer screen threw "Interface ... not found".
        self::assertTrue(
            interface_exists('Phlix\\Console\\SugarCraft\\Screen\\Breadcrumbed'),
            'Breadcrumbed must resolve via the PSR-4 autoloader at its mapped path.'
        );
        self::assertTrue(
            interface_exists('Phlix\\Console\\SugarCraft\\Screen\\Themed'),
            'Themed must resolve via the PSR-4 autoloader at its mapped path.'
        );
    }

    public function testThePreviouslyFatalScreensLinkThroughTheAutoloader(): void
    {
        // class_exists() with autoload resolves the whole implements-chain, so a
        // missing interface parent makes exactly these two probes fatal-red.
        self::assertTrue(class_exists('Phlix\\Console\\Screen\\SharedWithMeScreen'));
        self::assertTrue(class_exists('Phlix\\Console\\Screen\\InviteLinksScreen'));
    }

    public function testEveryFilePathMatchesItsDeclaredNamespace(): void
    {
        $violations = [];
        $root = self::repoRoot();

        foreach (self::srcFiles() as $relativeFile) {
            $source = (string) file_get_contents($root . '/' . $relativeFile);
            if (!preg_match('/^namespace\s+([^;{\s]+)\s*[;{]/m', $source, $match)) {
                $violations[] = "$relativeFile: no namespace declaration (every src/ file must be namespaced)";
                continue;
            }

            $namespace = $match[1];
            $expected = self::expectedPath($namespace, $relativeFile);
            if ($expected !== $relativeFile) {
                $violations[] = "$relativeFile: namespace $namespace maps to $expected";
            }

            // PSR-4 only autoloads the symbol named after the file itself; every
            // mapped file must actually declare it (or the prefix mapping is a
            // lie even when the path is tidy).
            $symbol = basename($relativeFile, '.php');
            $declarePattern = '/^(?:final\s+|abstract\s+|readonly\s+)*'
                . '(?:class|interface|trait|enum)\s+' . preg_quote($symbol, '/') . '\b/mi';
            if (!preg_match($declarePattern, $source)) {
                $violations[] = "$relativeFile: declares no top-level $symbol";
            }
        }

        self::assertSame([], $violations, "PSR-4 map drift in src/:\n" . implode("\n", $violations));
    }

    public function testEveryScreenLinksThroughTheComposerAutoloader(): void
    {
        $root = self::repoRoot() . '/src/Screen';
        $unresolved = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $fqcn = 'Phlix\\Console\\Screen\\' . $file->getBasename('.php');
            $loaded = class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn);
            if (!$loaded) {
                $unresolved[] = $fqcn;
            }
        }

        self::assertSame([], $unresolved, "screens unreachable through the autoloader:\n" . implode("\n", $unresolved));
    }
}

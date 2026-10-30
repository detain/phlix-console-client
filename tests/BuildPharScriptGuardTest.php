<?php

/**
 * Guard against the M-CONSOLE-24/28 class (review console minors W-min20):
 * scripts/build-phar.sh — the documented one-command PHAR build — used to run
 * `composer require --dev humbug/box` inside its box-missing branch. That
 * MUTATES the tracked composer.json and composer.lock as a side effect of
 * building, dirtying the working tree of every developer (and any CI leg)
 * that ran the script on a checkout without box on PATH.
 *
 * The shipped shape resolves box by capability instead: PATH, then the
 * declared humbug/box dev dependency via a non-mutating `composer install`,
 * then a pinned-latest box.phar download to /tmp. The script may never again
 * carry a `composer require` invocation in executable code (comment prose
 * naming the retired command is fine — this walk strips comments).
 *
 * Doctrine, per DiagnosticsCurlGuardTest: the census is DERIVED from the
 * script text, never restated from prose; and the premises the ladder relies
 * on are pinned against their sources of truth (composer.json require-dev
 * must actually declare humbug/box, or the `composer install` arm is a lie).
 */

declare(strict_types=1);

namespace Phlix\Console\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BuildPharScriptGuardTest extends TestCase
{
    /** Source of truth: the PHAR build script. */
    private const SCRIPT_SOURCE = 'scripts/build-phar.sh';

    public function testTheScriptCarriesNoComposerRequireInvocation(): void
    {
        $code = self::executableCode();

        self::assertStringNotContainsString(
            'composer require',
            $code,
            self::SCRIPT_SOURCE . ': executable code invokes `composer require`, which mutates '
            . 'the tracked composer.json/composer.lock during a build (the M-CONSOLE-24/28 '
            . 'defect). Resolve box from PATH, from `composer install` (declared dev '
            . 'dependency), or from the box.phar download fallback.'
        );
    }

    public function testTheBoxLadderIsInstallThenDownload(): void
    {
        $code = self::executableCode();

        // Every arm the box resolution relies on must be present: the declared
        // dev dependency gets installed non-mutatingly, its vendor bin is
        // re-checked, and the last-resort download lands OUTSIDE the repo.
        self::assertStringContainsString(
            'composer install',
            $code,
            self::SCRIPT_SOURCE . ': the non-mutating dev-dependency install arm disappeared.'
        );
        self::assertStringContainsString(
            'vendor/bin/box',
            $code,
            self::SCRIPT_SOURCE . ': the vendor/bin/box resolution arm disappeared.'
        );
        self::assertStringContainsString(
            'https://github.com/box-project/box/releases/latest/download/box.phar',
            $code,
            self::SCRIPT_SOURCE . ': the box.phar download fallback disappeared.'
        );
        self::assertStringContainsString(
            'BOX_BIN="/tmp/box.phar"',
            $code,
            self::SCRIPT_SOURCE . ': the fallback must download to /tmp, never into the repository.'
        );
    }

    public function testBoxIsADeclaredDevDependency(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(self::repoPath('composer.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($manifest);
        self::assertArrayHasKey(
            'humbug/box',
            $manifest['require-dev'] ?? [],
            'composer.json must keep declaring humbug/box as a dev dependency: build-phar.sh '
            . 'resolves box through `composer install`, which only provides declared packages.'
        );
    }

    public function testTheScriptWalkIsNotVacuous(): void
    {
        $code = self::executableCode();

        // Green-by-vacuity guard: the comment-stripping walk must still see
        // real script body (the ladder calls composer and curl).
        self::assertStringContainsString(
            'curl -fsSL',
            $code,
            self::SCRIPT_SOURCE . ': the executable-code walk lost the script body — '
            . 'the guard would pass vacuously.'
        );
    }

    /**
     * The script with full-line comments removed: `#` introducers (leading
     * whitespace allowed) and the shebang. Trailing `#` prose inside command
     * arguments is retained — no shipped line relies on it, and the stricter
     * the scan, the fewer false kills.
     *
     * @return non-empty-string
     */
    private static function executableCode(): string
    {
        $script = file_get_contents(self::repoPath(self::SCRIPT_SOURCE));
        if (!is_string($script)) {
            throw new RuntimeException('Unreadable ' . self::SCRIPT_SOURCE . ' — run from the repository root.');
        }

        $kept = [];
        foreach (explode("\n", $script) as $line) {
            if (preg_match('/^\s*(#|$)/', $line) === 1) {
                continue;
            }
            $kept[] = $line;
        }

        $code = implode("\n", $kept);
        if (trim($code) === '') {
            throw new RuntimeException(self::SCRIPT_SOURCE . ' parsed to empty executable code.');
        }

        return $code;
    }

    /**
     * Resolve a repository-root-relative path independent of the CWD PHPUnit
     * was launched from.
     */
    private static function repoPath(string $relative): string
    {
        $root = dirname(__DIR__);
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException("Expected {$relative} to exist at {$path}.");
        }

        return $path;
    }
}

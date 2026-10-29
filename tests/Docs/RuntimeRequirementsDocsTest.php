<?php

/**
 * Guard against the M-CONSOLE-13 class (review console__devops): the README
 * once claimed `pcntl` + `posix` as runtime requirements for "TTY/raw mode"
 * while omitting every extension composer actually enforces (gd, mbstring,
 * intl, ffi, json) and the curl the diagnostics commands call. Raw mode is
 * plain `stty` (vendor sugar-core Util/RawMode.php); pcntl/posix appear in
 * vendor only behind function_exists() guards (Ctrl-Z suspend) and in one
 * self-skipping fork-based test.
 *
 * The enforced set is derived from composer.lock production requires — never
 * restated from prose — and pinned against every runtime-requirements list
 * in the repo: README.md "## Requirements" and "**Requirements for PHAR:**",
 * docs/clients/console.md "**PHAR requirements:**", and the two release-note
 * bodies in .github/workflows/build.yml. A new transitive ext-* require
 * reddens the census pin here and its message directs all five doc sites and
 * this pin to move together — the same doctrine as TokenFileNameDocsTest.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuntimeRequirementsDocsTest extends TestCase
{
    /**
     * Census pin: every ext-* the production install is hard-gated on by
     * transitive composer requirements (sorted). A lock re-resolve that adds
     * or drops one reddens this test: update the requirements lists in all
     * five doc sites AND rotate this list in the same change.
     *
     * @var list<string>
     */
    private const LOCK_ENFORCED_EXTENSIONS = ['ffi', 'gd', 'intl', 'json', 'mbstring'];

    /**
     * Extra extension the docs must keep naming: bin/phlix diagnostics call
     * curl unguarded, so provisioning guides must list it even though
     * composer does not (see testTheTuiCoreItselfUsesNoCurl).
     */
    private const DIAGNOSTICS_EXTENSION = 'curl';

    /** Section start/end markers of the source-install list in README.md. */
    private const README_SECTION_START = "## Requirements\n";
    private const README_SOURCE_END = "\n## Install";

    /** Marker of the PHAR list in README.md / docs/clients/console.md. */
    private const README_PHAR_MARKER = '**Requirements for PHAR:**';
    private const README_PHAR_END = "\nTo build your own PHAR";
    private const CONSOLE_PHAR_MARKER = '**PHAR requirements:**';
    private const CONSOLE_PHAR_END = "\n### Building the PHAR";

    public function testTheLockEnforcesThePinnedExtensionCensus(): void
    {
        self::assertSame(
            self::LOCK_ENFORCED_EXTENSIONS,
            self::lockEnforcedExtensions(),
            'composer.lock production requires gained or lost an ext-*: '
            . 'update README.md (both lists), docs/clients/console.md, the two '
            . 'build.yml release-note bodies, and LOCK_ENFORCED_EXTENSIONS together.'
        );
    }

    public function testTheReadmeSourceRequirementsNameEveryEnforcedExtension(): void
    {
        $section = self::extract(self::read('README.md'), self::README_SECTION_START, self::README_SOURCE_END);

        foreach (self::documentedExtensionTokens() as $token) {
            self::assertStringContainsString(
                $token,
                $section,
                'README.md "## Requirements" no longer names ' . $token
                . ' (composer.lock enforcement / diagnostics reality moved; move the prose with it).'
            );
        }
    }

    /** @return array<string, array{0: string}> */
    public static function pharRequirementsSites(): array
    {
        return [
            'README.md PHAR list' => ['readmePhar'],
            'console.md PHAR list' => ['consolePhar'],
        ];
    }

    #[DataProvider('pharRequirementsSites')]
    public function testThePharRequirementsSitesNameEveryEnforcedExtension(string $site): void
    {
        $section = match ($site) {
            'readmePhar' => self::extract(
                self::read('README.md'),
                self::README_PHAR_MARKER,
                self::README_PHAR_END
            ),
            'consolePhar' => self::extract(
                self::read('docs/clients/console.md'),
                self::CONSOLE_PHAR_MARKER,
                self::CONSOLE_PHAR_END
            ),
            default => throw new RuntimeException('Unknown requirements site: ' . $site),
        };

        foreach (self::documentedExtensionTokens() as $token) {
            self::assertStringContainsString(
                $token,
                $section,
                $site . ' no longer names ' . $token
                . ' — the PHAR bypasses composer platform checks, so its runtime list must be complete.'
            );
        }
    }

    public function testTheReleaseNoteBodiesCarryTheEnforcedSet(): void
    {
        preg_match_all('/needs PHP 8\.3\+.*?video frames\)\./s', self::read('.github/workflows/build.yml'), $notes);

        self::assertCount(
            2,
            $notes[0],
            'build.yml is expected to carry exactly two "needs PHP 8.3+" release-note sentences '
            . '(rolling latest + versioned); if a body moved or reworded, update this pin and the prose together.'
        );

        foreach ($notes[0] as $i => $note) {
            foreach (self::documentedExtensionTokens() as $token) {
                self::assertStringContainsString(
                    $token,
                    $note,
                    'build.yml release note #' . ($i + 1) . ' no longer names ' . $token
                    . '; shipped PHAR users read exactly this list.'
                );
            }
        }
    }

    public function testNoRequirementsSiteClaimsPcntlOrPosixAsRequired(): void
    {
        $sites = [
            'README.md "## Requirements"' => self::extract(
                self::read('README.md'),
                self::README_SECTION_START,
                self::README_SOURCE_END
            ),
            'README.md PHAR list' => self::extract(
                self::read('README.md'),
                self::README_PHAR_MARKER,
                self::README_PHAR_END
            ),
            'console.md PHAR list' => self::extract(
                self::read('docs/clients/console.md'),
                self::CONSOLE_PHAR_MARKER,
                self::CONSOLE_PHAR_END
            ),
        ];

        foreach ($sites as $label => $text) {
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                if (!preg_match('/pcntl|posix/i', $line)) {
                    continue;
                }

                self::assertStringContainsString(
                    'optional',
                    $line,
                    $label . ' names pcntl/posix without calling them optional: ' . trim($line)
                    . ' — they are guarded vendor sugar (Ctrl-Z suspend) and a self-skipping '
                    . 'fork test, never a TTY/raw-mode runtime need.'
                );
            }
        }
    }

    public function testTheTuiCoreItselfUsesNoCurl(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('src'));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents !== false && preg_match('/\bcurl_[a-z]+\(/', $contents) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame(
            [],
            $offenders,
            'src/ now calls curl directly; the docs name ext-curl as a diagnostics-only '
            . 'requirement (doctor / run --selftest in bin/phlix). Either re-word the '
            . 'requirements lists or move this call out of the TUI core, in the same change.'
        );
    }

    /**
     * The ext-* names every requirements site must carry: the lock-enforced
     * five plus the curl the diagnostics call unguarded.
     *
     * @return list<string>
     */
    private static function documentedExtensionTokens(): array
    {
        $tokens = array_map(static fn (string $e): string => 'ext-' . $e, self::LOCK_ENFORCED_EXTENSIONS);
        $tokens[] = 'ext-' . self::DIAGNOSTICS_EXTENSION;

        return $tokens;
    }

    /** @return list<string> */
    private static function lockEnforcedExtensions(): array
    {
        $decoded = json_decode(self::read('composer.lock'), true);
        if (!is_array($decoded) || !isset($decoded['packages']) || !is_array($decoded['packages'])) {
            throw new RuntimeException('composer.lock unreadable or malformed.');
        }

        $extensions = [];
        foreach ($decoded['packages'] as $package) {
            foreach ((array) ($package['require'] ?? []) as $name => $constraint) {
                if (is_string($name) && str_starts_with($name, 'ext-')) {
                    $extensions[substr($name, 4)] = true;
                }
            }
        }

        $sorted = array_keys($extensions);
        sort($sorted);

        return $sorted;
    }

    private static function extract(string $text, string $startMarker, string $endMarker): string
    {
        $start = strpos($text, $startMarker);
        if ($start === false) {
            throw new RuntimeException('Requirements section start marker not found: ' . $startMarker);
        }

        $start += strlen($startMarker);
        $end = strpos($text, $endMarker, $start);
        if ($end === false) {
            throw new RuntimeException('Requirements section end marker not found: ' . $endMarker);
        }

        return substr($text, $start, $end - $start);
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents($relative);
        if ($contents === false) {
            throw new RuntimeException('Cannot read repo file: ' . $relative);
        }

        return $contents;
    }
}

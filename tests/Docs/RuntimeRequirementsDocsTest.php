<?php

/**
 * Guard against the M-CONSOLE-13 class (review console__devops): the README
 * once claimed `pcntl` + `posix` as runtime requirements for "TTY/raw mode"
 * while omitting every extension composer actually enforces (gd, mbstring,
 * intl, ffi, json) and the curl the diagnostics commands and watch's
 * relay-token mint call. Raw mode is
 * plain `stty` (vendor sugar-core Util/RawMode.php); pcntl/posix appear in
 * vendor only behind function_exists() guards (Ctrl-Z suspend) and in two
 * self-skipping fork-based tests (SyncPlay bearer-carrier + TLS-dial
 * round-trip).
 *
 * The enforced set is derived from composer.lock production requires PLUS
 * the root composer.json require block — never restated from prose — and
 * pinned against every runtime-requirements list in the repo: README.md
 * "## Requirements" and "**Requirements for PHAR:**",
 * docs/clients/console.md "**PHAR requirements:**", and the two release-note
 * bodies in .github/workflows/build.yml. A new ext-* require (transitive OR
 * root) reddens the census pin here and its message directs all five doc
 * sites and this pin to move together — the same doctrine as
 * TokenFileNameDocsTest.
 *
 * pcntl truth [CON-S13 / CON-14]: the census once read only the lock, so the
 * ROOT `ext-pcntl` require was invisible to it and every site called pcntl
 * "optional" while `composer install` hard-fails without it. The owner-visible
 * choice is: keep the hard require, and say so — "`ext-pcntl` is
 * composer-enforced (soft only in PHAR)". Soft-in-PHAR is code truth, not
 * prose: a PHAR skips composer's platform check and every pcntl call in
 * src/ and bin/ sits behind a function_exists() guard (pinned below), so a
 * pcntl-less PHAR still boots but loses the exit-signal teardown and Ctrl-Z
 * suspend. `posix` stays genuinely optional (Ctrl-Z suspend + two
 * self-skipping fork tests) and must still be called that.
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
     * composer — transitive lock requires AND the root composer.json require
     * block (sorted). A lock re-resolve or a root require edit that adds or
     * drops one reddens this test: update the requirements lists in all five
     * doc sites AND rotate this list in the same change.
     *
     * @var list<string>
     */
    private const COMPOSER_ENFORCED_EXTENSIONS = ['ffi', 'gd', 'intl', 'json', 'mbstring', 'pcntl'];

    /**
     * The one qualifier every requirements site must carry for pcntl
     * (whitespace-normalized): composer hard-requires it, the PHAR runs
     * without that platform check and degrades because every call is guarded.
     */
    private const PCNTL_QUALIFIER = '`ext-pcntl` is composer-enforced (soft only in PHAR)';

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

    public function testComposerEnforcesThePinnedExtensionCensus(): void
    {
        self::assertSame(
            self::COMPOSER_ENFORCED_EXTENSIONS,
            self::composerEnforcedExtensions(),
            'composer.lock production requires or the root composer.json require gained or lost an ext-*: '
            . 'update README.md (both lists), docs/clients/console.md, the two '
            . 'build.yml release-note bodies, and COMPOSER_ENFORCED_EXTENSIONS together.'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function allRequirementsSites(): array
    {
        $sites = [];
        foreach (array_keys(self::sites()) as $label) {
            $sites[$label] = [$label];
        }

        return $sites;
    }

    #[DataProvider('allRequirementsSites')]
    public function testEverySiteCallsPcntlComposerEnforcedSoftOnlyInPhar(string $label): void
    {
        self::assertStringContainsString(
            self::PCNTL_QUALIFIER,
            self::normalize(self::sites()[$label]),
            $label . ' must say ' . self::PCNTL_QUALIFIER . ' — composer.json hard-requires it '
            . '(a source install without it fails), while the PHAR degrades behind function_exists() guards.'
        );
    }

    public function testEveryPcntlCallIsFunctionExistsGuarded(): void
    {
        $unguarded = [];
        $paths = ['bin/phlix'];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('src'));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        foreach ($paths as $path) {
            $contents = self::read($path);
            $callsPcntl = preg_match('/\bpcntl_[a-z_]+\(/', $contents) === 1;
            if ($callsPcntl && !str_contains($contents, "function_exists('pcntl_")) {
                $unguarded[] = $path;
            }
        }

        self::assertSame(
            [],
            $unguarded,
            'a pcntl call without a function_exists() guard makes "soft only in PHAR" a lie: a PHAR skips '
            . 'composer\'s platform check, so on a pcntl-less PHP that call is an undefined-function fatal. Guard it, '
            . 'or move every requirements site to call pcntl a hard runtime requirement in the same change.'
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
                . ' (composer enforcement (lock + root require) / diagnostics reality moved; move the prose with it).'
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

    public function testNoSiteCallsPcntlOptionalAndPosixStaysOptional(): void
    {
        foreach (self::sites() as $label => $text) {
            foreach (preg_split('/(?<=[.;])\s/', self::normalize($text)) ?: [] as $sentence) {
                if (preg_match('/pcntl/i', $sentence) === 1) {
                    self::assertStringNotContainsStringIgnoringCase(
                        'optional',
                        $sentence,
                        $label . ' calls pcntl optional: ' . $sentence
                        . ' — composer.json hard-requires ext-pcntl; the only soft leg is the PHAR.'
                    );
                }
            }

            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                if (preg_match('/posix/i', $line) !== 1) {
                    continue;
                }

                self::assertStringContainsString(
                    'optional',
                    $line,
                    $label . ' names posix without calling it optional: ' . trim($line)
                    . ' — it is guarded vendor sugar (Ctrl-Z suspend) and two '
                    . 'self-skipping fork tests, never a TTY/raw-mode runtime need.'
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
            'src/ now calls curl directly; the docs name ext-curl as a diagnostics-and-relay-mint '
            . 'requirement (doctor / run --selftest / watch relay-token mint in bin/phlix). Either re-word the '
            . 'requirements lists or move this call out of the TUI core, in the same change.'
        );
    }

    /**
     * The ext-* names every requirements site must carry: the
     * composer-enforced six plus the curl the diagnostics call unguarded.
     *
     * @return list<string>
     */
    private static function documentedExtensionTokens(): array
    {
        $tokens = array_map(static fn (string $e): string => 'ext-' . $e, self::COMPOSER_ENFORCED_EXTENSIONS);
        $tokens[] = 'ext-' . self::DIAGNOSTICS_EXTENSION;

        return $tokens;
    }

    /**
     * Every runtime-requirements site, label => raw text.
     *
     * @return array<string, string>
     */
    private static function sites(): array
    {
        $readme = self::read('README.md');
        $sites = [
            'README.md "## Requirements"' => self::extract(
                $readme,
                self::README_SECTION_START,
                self::README_SOURCE_END
            ),
            'README.md PHAR list' => self::extract($readme, self::README_PHAR_MARKER, self::README_PHAR_END),
            'console.md PHAR list' => self::extract(
                self::read('docs/clients/console.md'),
                self::CONSOLE_PHAR_MARKER,
                self::CONSOLE_PHAR_END
            ),
        ];

        preg_match_all('/needs PHP 8\.3\+.*?video frames\)\./s', self::read('.github/workflows/build.yml'), $notes);
        foreach ($notes[0] as $i => $note) {
            $sites['build.yml release note #' . ($i + 1)] = $note;
        }

        return $sites;
    }

    private static function normalize(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    /** @return list<string> */
    private static function composerEnforcedExtensions(): array
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

        $root = json_decode(self::read('composer.json'), true);
        if (!is_array($root)) {
            throw new RuntimeException('composer.json unreadable or malformed.');
        }
        foreach ((array) ($root['require'] ?? []) as $name => $constraint) {
            if (is_string($name) && str_starts_with($name, 'ext-')) {
                $extensions[substr($name, 4)] = true;
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

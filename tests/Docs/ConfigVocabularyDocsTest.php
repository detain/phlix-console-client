<?php

/**
 * Guard against the M-CONSOLE-38 class (review console__tuiarch): Config once
 * carried a whole invented vocabulary — device-local keys
 * `terminal_render_mode`, `colour_palette`, `cell_size` (the `DEVICE_LOCAL_KEYS`
 * constant plus value sets in the class docblock) and "server-persisted" keys
 * `preferred_library_ids` / `parental_controls_pin` — that NO code path ever
 * read, parsed, or sent. The manual meanwhile advertised config.json as holding
 * "render mode preferences", so a user following the docs got a silent no-op:
 * a documented key that must bind to behavior, bound to nothing.
 *
 * The cure deleted the vocabulary and told the truth. Doctrine, per
 * TokenFileNameDocsTest and ScreenInventoryDocsTest: real state is DERIVED
 * from the code at runtime, never restated from prose. Five laws:
 *
 * 1. src/ and bin/ mention none of the dead key names or the dead constant.
 * 2. README.md and docs/ mention none of the dead key names either — the
 *    purge covers prose; re-documenting a key must start by wiring a reader.
 * 3. The set of keys config.json actually holds is derived by RUNNING
 *    Config::save() into a scratch file; the manual's config.json table row
 *    must match that truth (names theme + slideshow interval, drops the
 *    "render mode preferences" claim).
 * 4. The manual states the render mode is a per-invocation choice, never
 *    stored in config.json.
 * 5. Every settings-endpoint cite matches the REAL path derived from
 *    ApiClient.php's own request literals; the short fossil form
 *    `/api/v1/me/settings` appears nowhere.
 * 6. The manual's config-directory precedence mirrors how Config::dir()
 *    actually runs — `XDG_CONFIG_HOME` honoured on EVERY platform first
 *    (derived live, not restated), no Linux/macOS-promise fallback split
 *    (review M-CONSOLE-20 · 43 cured the inverted bullets; re-inverting
 *    them reddens here).
 *
 * A future lane that wires persisted render mode (the review's other arm)
 * must move code, both docs, and this pin together — reddening here names
 * all three.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use Phlix\Console\Config\Config;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ConfigVocabularyDocsTest extends TestCase
{
    /**
     * Key names the code never read (and docs must not re-advertise until a
     * reader exists). The class constant fell with them.
     *
     * @var list<string>
     */
    private const DEAD_VOCABULARY = [
        'terminal_render_mode',
        'colour_palette',
        'cell_size',
        'preferred_library_ids',
        'parental_controls_pin',
        'DEVICE_LOCAL_KEYS',
    ];

    /**
     * The complete truth of what config.json persists — derived by running
     * save() below and pinned against drift in either direction.
     *
     * @var list<string>
     */
    private const PERSISTED_KEYS = [
        'active_server_id',
        'servers',
        'slideshow_interval',
        'theme',
    ];

    private const MANUAL = 'docs/clients/console.md';

    public function testCodeCarriesNoDeadConfigVocabulary(): void
    {
        // bin/phlix carries no extension — an empty suffix admits it.
        $hits = self::findVocabulary(['src', 'bin'], ['php', '']);

        self::assertSame(
            [],
            $hits,
            'src/ or bin/ mention config keys no code reads (review M-CONSOLE-38). '
            . 'Either wire a real reader for the key (and update both docs and this pin) '
            . 'or delete the mention: ' . self::describe($hits)
        );
    }

    public function testDocsCarryNoDeadConfigVocabulary(): void
    {
        $hits = self::findVocabulary(['docs'], ['md']);

        self::assertSame(
            [],
            $hits,
            'docs/ advertise config keys the code never reads (review M-CONSOLE-38): '
            . self::describe($hits)
        );
    }

    public function testTheManualConfigRowMatchesTheRuntimePersistedKeys(): void
    {
        // Derive, do not restate: run the real writer into a scratch file.
        // The name carries a per-process token (rider R-CONSOLE-12): the old
        // fixed name was safe only under the serial-phpunit law and CI's
        // separate runners — a suite parallelizing on one host would race two
        // processes through one path. The unlink is part of the same deal:
        // tokenised names that leak on every run are worse hygiene than the
        // fixed name they replace, so the scratch is removed in `finally`
        // (assertion throws included).
        $path = sys_get_temp_dir() . '/phlix-m38-config-vocabulary-' . getmypid() . '.json';
        try {
            $config = new Config(
                servers: [],
                activeServerId: 'srv-derived',
                theme: 'nocturne',
                slideshowInterval: 7,
            );
            $config->save($path);

            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException('could not read scratch config at ' . $path);
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('scratch config is not JSON at ' . $path);
            }

            $persisted = array_keys($decoded);
            sort($persisted);
            self::assertSame(
                self::PERSISTED_KEYS,
                $persisted,
                'Config::save() now persists ' . implode(', ', $persisted)
                . ' — the manual row, the dev-guide split, and PERSISTED_KEYS here '
                . 'must all move together.'
            );

            $row = self::configJsonRow();
            self::assertStringContainsString(
                'theme',
                $row,
                'the manual config.json row no longer names the synced theme key.'
            );
            self::assertStringContainsString(
                'slideshow',
                $row,
                'the manual config.json row no longer names the slideshow interval '
                . 'that save() provably persists.'
            );
            self::assertStringNotContainsString(
                'render mode preferences',
                $row,
                'the manual again advertises persisted render-mode preferences — '
                . 'render mode is per-invocation only (review M-CONSOLE-38).'
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testTheManualStatesRenderModeIsNeverStored(): void
    {
        $section = self::section(self::readRepoFile(self::MANUAL), "### Render modes\n");

        self::assertStringContainsString(
            'never stored in `config.json`',
            preg_replace('/\s+/', ' ', $section) ?? $section,
            'the manual Render modes section lost the statement that the mode '
            . 'is a per-invocation choice, never persisted (review M-CONSOLE-38).'
        );
    }

    public function testSettingsEndpointCitesMatchTheRealPath(): void
    {
        // Derive the live path from the client's own request literals.
        $api = self::readRepoFile('src/Api/ApiClient.php');
        $ok = preg_match_all(
            "/authed\\('(GET|PUT)', '([^']*\\/settings)'/",
            $api,
            $matches
        );
        if (!$ok || !isset($matches[2]) || $matches[2] === []) {
            throw new RuntimeException(
                'could not derive the user-settings endpoint literals from ApiClient.php — '
                . 'the fossil-cite pin needs its anchor; inspect the authed() calls.'
            );
        }

        $real = array_values(array_unique($matches[2]));
        sort($real);

        $fossil = [];
        foreach ($real as $path) {
            $short = str_replace('/users/me/', '/me/', $path);
            if ($short !== $path) {
                $fossil[] = $short;
            }
        }
        self::assertNotEmpty(
            $fossil,
            'the derived settings paths no longer contain a /users/me/ segment — '
            . 'this pin (and the prose it guards) needs a deliberate update.'
        );

        $hits = [];
        foreach (self::scannedFiles(['src', 'bin', 'docs'], ['php', '', 'md']) as $relative => $text) {
            foreach ($fossil as $short) {
                if (str_contains($text, $short)) {
                    $hits[] = $relative . ' cites ' . $short
                        . ' (real path: ' . implode(', ', $real) . ')';
                }
            }
        }
        $readme = self::readRepoFile('README.md');
        foreach ($fossil as $short) {
            if (str_contains($readme, $short)) {
                $hits[] = 'README.md cites ' . $short
                    . ' (real path: ' . implode(', ', $real) . ')';
            }
        }

        self::assertSame(
            [],
            $hits,
            'a settings endpoint is cited with the fossil short path: '
            . self::describe($hits)
        );
    }

    public function testTheManualStatesConfigDirPrecedenceAsCodeRunsIt(): void
    {
        // Derive the truth by running dir() under a scratch XDG_CONFIG_HOME.
        $previous = getenv('XDG_CONFIG_HOME');
        $probe = sys_get_temp_dir() . '/phlix-wmin12-xdg-probe';
        putenv('XDG_CONFIG_HOME=' . $probe);
        try {
            $withXdg = Config::dir();
        } finally {
            if ($previous === false) {
                putenv('XDG_CONFIG_HOME=');
            } else {
                putenv('XDG_CONFIG_HOME=' . $previous);
            }
        }

        self::assertSame(
            $probe . '/phlix',
            $withXdg,
            'Config::dir() no longer honours XDG_CONFIG_HOME unconditionally — '
            . 'if a platform branch was added, the manual bullets must move with it.'
        );

        $manual = self::readRepoFile(self::MANUAL);
        self::assertStringNotContainsString(
            '**Linux/macOS:** `~/.config/phlix/`',
            $manual,
            'the manual re-promises the plain ~/.config path on Linux/macOS — '
            . 'that was the inverted-precedence defect (review M-CONSOLE-20 · 43).'
        );

        $section = self::section($manual, "### Config directory\n");
        self::assertStringContainsString(
            '$XDG_CONFIG_HOME/phlix/',
            $section,
            'the Config directory section lost its statement that '
            . 'XDG_CONFIG_HOME takes precedence.'
        );
        self::assertStringContainsString(
            '%USERPROFILE%',
            $section,
            'the Config directory section no longer notes the Windows '
            . 'HOME → USERPROFILE → temp fallback that dir() performs.'
        );
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @param list<string> $dirs
     * @param list<string> $extensions bare getExtension() names ('php', 'md', '' for extensionless files)
     * @return list<string> human-readable "file :: needle" hits
     */
    private static function findVocabulary(array $dirs, array $extensions): array
    {
        $hits = [];
        foreach (self::scannedFiles($dirs, $extensions) as $relative => $text) {
            foreach (self::DEAD_VOCABULARY as $needle) {
                if (str_contains($text, $needle)) {
                    $hits[] = $relative . ' :: ' . $needle;
                }
            }
        }

        // README.md is prose surface too — never exempt from the purge.
        $readme = self::readRepoFile('README.md');
        foreach (self::DEAD_VOCABULARY as $needle) {
            if (str_contains($readme, $needle)) {
                $hits[] = 'README.md :: ' . $needle;
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $dirs
     * @param list<string> $extensions
     * @return array<string, string> repo-relative path => contents
     */
    private static function scannedFiles(array $dirs, array $extensions): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $root = dirname(__DIR__, 2) . '/' . $dir;
            if (!is_dir($root)) {
                throw new RuntimeException('expected scan root missing: ' . $root);
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $info */
            foreach ($iterator as $info) {
                if (!$info->isFile()) {
                    continue;
                }
                if (!in_array($info->getExtension(), $extensions, true)) {
                    continue;
                }
                $files[substr($info->getPathname(), strlen(dirname(__DIR__, 2)) + 1)] =
                    file_get_contents($info->getPathname()) ?: '';
            }
        }

        return $files;
    }

    /** The single manual table row describing what config.json holds. */
    private static function configJsonRow(): string
    {
        $manual = self::readRepoFile(self::MANUAL);
        foreach (explode("\n", $manual) as $line) {
            if (str_starts_with(trim($line), '| `config.json`')) {
                return $line;
            }
        }

        throw new RuntimeException(
            'the manual no longer carries a "| `config.json` |" table row — '
            . 'this pin and docs/clients/console.md must move together.'
        );
    }

    /** Text from $heading to the next "## " heading (or EOF). */
    private static function section(string $text, string $heading): string
    {
        $start = strpos($text, "\n" . $heading);
        if ($start === false) {
            throw new RuntimeException('missing heading: ' . trim($heading));
        }
        $rest = substr($text, (int) $start + strlen("\n" . $heading));
        $break = strpos($rest, "\n## ");

        return $break === false ? $rest : substr($rest, 0, $break);
    }

    /** @param list<string> $hits */
    private static function describe(array $hits): string
    {
        return implode('; ', $hits);
    }

    private static function readRepoFile(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException('cannot read repo file: ' . $relative);
        }

        return $text;
    }
}

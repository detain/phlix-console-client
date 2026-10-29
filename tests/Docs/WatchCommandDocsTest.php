<?php

/**
 * Guard against the M-CONSOLE-14 class (review console__docmaint, M1): the
 * `watch` command shipped in bin/phlix (hub-relay consumer, S298 console half)
 * with four env vars while every docs surface omitted it — the README listed
 * only doctor/poster/frame as the non-interactive commands and the dev-guide
 * CLI diagram read "doctor, poster, frame, run". A credential-bearing,
 * first-class entrypoint was undiscoverable from the docs alone.
 *
 * The four names are derived from the getenv() calls inside parseWatchArgs in
 * bin/phlix — never restated from prose — together with a whole-entrypoint env
 * census, and pinned into every surface that must carry them: the README
 * "Hub relay watch" section, the docs/clients/console.md env-var table and the
 * "Hub relay consumer" usage heading, and the dev-guide diagram line. A watch
 * env added or renamed reddens the pin here and its message directs docs and
 * pin to move together — the same doctrine as TokenFileNameDocsTest and
 * RuntimeRequirementsDocsTest. The diagram gains an alignment pin because its
 * command list is prose inside fixed-width ASCII art.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WatchCommandDocsTest extends TestCase
{
    /**
     * Census pin: every PHLIX_* environment variable the entrypoint reads,
     * sorted. A new getenv('PHLIX_*') in bin/phlix reddens this test: move the
     * env-var table in docs/clients/console.md, the relevant README section,
     * and BIN_ENV_CENSUS together in the same change.
     *
     * @var list<string>
     */
    private const BIN_ENV_CENSUS = [
        'PHLIX_HUB_SERVER_ID',
        'PHLIX_HUB_TOKEN',
        'PHLIX_HUB_URL',
        'PHLIX_POSTER_CACHE_ENTRIES',
        'PHLIX_RELAY_TOKEN',
        'PHLIX_SERVER_URL',
    ];

    /**
     * The watch-only subset of the census, re-derived from parseWatchArgs' own
     * body so the ladder cannot drift silently from what the docs promise.
     *
     * @var list<string>
     */
    private const WATCH_ENV_VARS = [
        'PHLIX_HUB_SERVER_ID',
        'PHLIX_HUB_TOKEN',
        'PHLIX_HUB_URL',
        'PHLIX_RELAY_TOKEN',
    ];

    public function testTheEntrypointEnvCensusIsThePinnedSet(): void
    {
        self::assertSame(
            self::BIN_ENV_CENSUS,
            self::envNamesReadIn(self::read('bin/phlix')),
            'bin/phlix gained or lost a getenv("PHLIX_*") read: update the env-var '
            . 'table in docs/clients/console.md, the README sections that name env '
            . 'vars, and BIN_ENV_CENSUS together.'
        );
    }

    public function testWatchReadsExactlyTheFourDocumentedEnvVars(): void
    {
        $body = self::functionBody(self::read('bin/phlix'), 'parseWatchArgs');

        self::assertSame(
            self::WATCH_ENV_VARS,
            self::envNamesReadIn($body),
            'parseWatchArgs() changed its env ladder: move the README "Hub relay '
            . 'watch" section, the docs/clients/console.md env-var table, the '
            . 'bin/phlix help text, and WATCH_ENV_VARS together.'
        );
    }

    public function testTheEntrypointAdvertisesWatchUsageAndCommand(): void
    {
        $entrypoint = self::read('bin/phlix');

        self::assertStringContainsString(
            'phlix watch [--hub URL] [--server-id ID]',
            $entrypoint,
            'bin/phlix no longer advertises the watch usage line the docs mirror.'
        );
        self::assertStringContainsString(
            "case 'watch':",
            $entrypoint,
            'bin/phlix no longer dispatches a `watch` command; every docs site '
            . 'naming it (README, console.md, dev-guide diagram) must move with it.'
        );
    }

    public function testTheReadmeCarriesTheHubRelayWatchSection(): void
    {
        $section = self::extract(
            self::read('README.md'),
            "### Hub relay watch\n",
            "\n## Test\n"
        );

        self::assertStringContainsString(
            'bin/phlix watch',
            $section,
            'README.md "Hub relay watch" no longer shows a watch invocation.'
        );
        self::assertStringContainsString(
            '8804/syncplay/',
            $section,
            'README.md no longer names the relay endpoint the command dials '
            . '(HubRelayConsumer DEFAULT_PORT + /syncplay/{server_id}).'
        );
        self::assertStringContainsString(
            '--once',
            $section,
            'README.md no longer documents --once (the headless single-command mode).'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function watchEnvVars(): array
    {
        $cases = [];
        foreach (self::WATCH_ENV_VARS as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    #[DataProvider('watchEnvVars')]
    public function testEveryWatchEnvVarIsNamedInTheReadmeSection(string $name): void
    {
        $section = self::extract(
            self::read('README.md'),
            "### Hub relay watch\n",
            "\n## Test\n"
        );

        self::assertStringContainsString(
            '`' . $name . '`',
            $section,
            'README.md "Hub relay watch" no longer names ' . $name
            . ' — bin/phlix parseWatchArgs() still reads it.'
        );
    }

    #[DataProvider('watchEnvVars')]
    public function testEveryWatchEnvVarHasItsConsoleDocTableRow(string $name): void
    {
        self::assertMatchesRegularExpression(
            '/^\| `' . preg_quote($name, '/') . '` \|/m',
            self::read('docs/clients/console.md'),
            'docs/clients/console.md lost the ' . $name . ' env-var table row.'
        );
    }

    public function testTheConsoleDocUsageSectionDocumentsWatch(): void
    {
        $console = self::read('docs/clients/console.md');

        self::assertStringContainsString(
            '### Hub relay consumer (`watch`)',
            $console,
            'docs/clients/console.md lost the watch usage heading.'
        );
        self::assertStringContainsString(
            'bin/phlix watch',
            $console,
            'docs/clients/console.md no longer shows a watch invocation.'
        );
    }

    public function testTheDevGuideDiagramListsWatch(): void
    {
        self::assertMatchesRegularExpression(
            '/CLI entrypoint:[^\n]*watch[^\n]*run/',
            self::read('docs/dev/client-console.md'),
            'docs/dev/client-console.md CLI diagram no longer lists the watch '
            . 'command between poster/frame and run.'
        );
    }

    public function testTheDevGuideDiagramRowsStayColumnAligned(): void
    {
        $lines = file('docs/dev/client-console.md');
        if ($lines === false) {
            throw new RuntimeException('Cannot read docs/dev/client-console.md.');
        }

        $anchor = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'CLI entrypoint:')) {
                $anchor = $i;
                break;
            }
        }
        self::assertNotNull($anchor, 'The dev-guide diagram lost its CLI entrypoint row.');

        $top = $anchor;
        while ($top >= 0 && !str_starts_with($lines[$top], '┌')) {
            $top--;
        }
        $bottom = $anchor;
        while (isset($lines[$bottom]) && !str_starts_with($lines[$bottom], '└')) {
            $bottom++;
        }
        self::assertGreaterThanOrEqual(0, $top, 'The entrypoint row has no box top edge above it.');
        self::assertLessThan(count($lines), (int) $bottom, 'The entrypoint row has no box bottom edge below it.');

        $widths = [];
        for ($i = $top + 1; $i < $bottom; $i++) {
            $row = rtrim($lines[$i]);
            if (str_starts_with($row, '│') && str_ends_with($row, '│')) {
                $widths[] = mb_strlen($row);
            }
        }

        self::assertNotEmpty($widths, 'No content rows found in the CLI diagram box.');
        self::assertSame(
            [max($widths)],
            array_values(array_unique($widths)),
            'The CLI diagram box rows are ragged: every │ … │ row must keep the '
            . 'same display width when the entrypoint list grows — pad the row.'
        );
    }

    /**
     * Sorted, de-duplicated PHLIX_* names this PHP text reads via getenv().
     *
     * @return list<string>
     */
    private static function envNamesReadIn(string $phpSource): array
    {
        preg_match_all("/getenv\('(PHLIX_[A-Z_]+)'\)/", $phpSource, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        /** @var list<string> $names */
        return $names;
    }

    /**
     * The body of a top-level function in a plain PHP script (column-0
     * declaration through its column-0 closing brace).
     */
    private static function functionBody(string $phpSource, string $function): string
    {
        if (preg_match('/^function ' . preg_quote($function, '/') . '\(.*?^\}/ms', $phpSource, $matches) === 1) {
            return $matches[0];
        }

        throw new RuntimeException('Top-level function not found in bin/phlix: ' . $function);
    }

    private static function extract(string $text, string $startMarker, string $endMarker): string
    {
        $start = strpos($text, $startMarker);
        if ($start === false) {
            throw new RuntimeException('Docs section start marker not found: ' . $startMarker);
        }

        $start += strlen($startMarker);
        $end = strpos($text, $endMarker, $start);
        if ($end === false) {
            throw new RuntimeException('Docs section end marker not found: ' . $endMarker);
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

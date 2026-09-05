<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\Unit\Api;

use Phlix\Console\Api\Cast\CastBackend;
use PHPUnit\Framework\TestCase;

/**
 * S405 console route gate (S280 closure for phlix-console-client).
 *
 * WHAT IT PINS: every URL the console's request-issuing code can put on the
 * SERVER wire is tuple-exact against the VENDORED phlix-server route manifest
 * (`tests/fixtures/server-route-manifest.json`, a byte-for-byte copy of
 * `@phlix/contracts` `dist/server-route-manifest.json`, 400 tuples @
 * phlix-server 3a253991). The expected set comes from the SERVER side only —
 * a manifest derived from the client it checks would self-adjust and pass
 * every defect it exists to catch (S276/S279/S280 shipped because no such
 * gate existed on console).
 *
 * Why VENDORING and not a pinned dependency: phlix-contracts is pin-free (a
 * pure-PHP repo) and the manifest export lives on master AHEAD of the v0.4.4
 * tag; this is the sanctioned interim pattern — identical to mobile
 * (dc45e5c3), roku (1da0910e) and tizen (a88bd5b7). Re-adoption of the
 * contracts export replaces the copies when the next contracts tag ships.
 *
 * MATCHING IS SEGMENT-EXACT, NEVER SUBSTRING: `{param}` segments are compared
 * as whole segments (client dynamic parts canonicalise to `{P}` from
 * token_get_all reconstruction); lengths must agree, so `/media/{id}` can
 * never absorb `/media/{id}/markers`. A client LITERAL that lands on a server
 * PARAM (`GET /media/match` riding `/media/{id}` with id='match' — the S405
 * discovery) is a collision the loose compare would hide, so every such
 * occurrence is pinned explicitly below; a new one fails the pin.
 *
 * COVERAGE IS A PIN, NOT A PROMISE: per-class anchor counts, expansion counts
 * per suffix helper, the cast suffix-literal set, per-command cast counts, the
 * hub negative partition per-PATH (console HubClient rides the shared
 * ApiClient — base flips at runtime — so per-FILE exclusion, which tizen
 * could use, is WRONG here), the WS socket pin and the per-file token-count
 * blindness sweep all RED on scanner blindness, silent shrink/grow, or new
 * request surfaces outside the enumerated shapes.
 *
 * SCAN METHODOLOGY (token_get_all — immune to docblock noise):
 * - anchors: authed/exchange/send/request with a literal verb first arg, and
 *   url(path, …) (GET). `self::CONST` resolved from per-file T_CONST harvest;
 *   rawurlencode/$var/method results → `{P}`; PHP 8 casts arrive as
 *   `(`/T_STRING/`)` (no T_CAST token).
 * - suffix helpers (`send('POST', self::BASE . $suffix)`): expanded from every
 *   same-file `->helper(…)` caller's first-argument literal expression.
 * - cast surface: composed at RUNTIME via the production CastBackend enum
 *   (cases/basePath/devicesPath/devicePath + capability guards), paired with
 *   the pinned CastClient literal-suffix set.
 * - returned serve-URL literals (subtitles/external) are pinned as a partition.
 *
 * KNOWN LIMIT (documented, same as the tizen gate): a URL deliberately SPLIT
 * across separate quoted fragments before the call (`const P = '/api'`;
 * `send('GET', P . '/v1/x')`) evades both scanner and sweep; none exists in
 * this tree, and the per-file token accounting makes any accidental split
 * fail the sweep (two swept fragments, one consumed).
 */
final class ServerRouteManifestGateTest extends TestCase
{
    private const GATE_ID = 's405-console-route-gate-v1';

    private const REPO_ROOT = __DIR__ . '/../../..';

    private const MANIFEST_PATH = __DIR__ . '/../../fixtures/server-route-manifest.json';

    private const EXPECTED_MD5 = '5bc7dd6d26b0f540eaa413ef66a1050d';

    private const EXPECTED_SERVER_SHA = '3a2539915767b5f156c5b676c753cf65e9208d3c';

    /**
     * Per-anchor-file reconstruction pins, measured on the tree at gate time.
     * A number moving means the client started/stopped issuing URLs (update
     * WITH the manifest check passing) or the scanner went blind (find out
     * which BEFORE touching a number).
     */
    private const PER_CLASS_ANCHORS = [
        'src/Api/Admin/AdminClient.php' => 118,
        'src/Api/ApiClient.php' => 67,
        'src/Api/Hub/HubClient.php' => 9,
        'src/Screen/RecommendationsScreen.php' => 1,
    ];

    /** url()-anchored sites (GET semantics). */
    private const URL_SITES = [
        ['src/Api/ApiClient.php', 'GET', '/api/v1/media/{P}/subtitles/{P}'],
    ];

    /** Variable-receiver composition sites (cast enum + transport terminal). */
    private const COMPOSITION_SITES = [
        'src/Api/ApiClient.php' => 1,
        'src/Api/Cast/CastClient.php' => 5,
    ];

    /** Expansion totals per suffix helper (glue sites are replaced by these). */
    private const EXPANSIONS_PER_HELPER = [
        'dlnaAction' => 2,
        'enqueue' => 6,
        'remoteAction' => 8,
    ];

    /** CastClient literal suffixes (new '/…' literal → RED). */
    private const CAST_SUFFIXES = [
        '/cast', '/key/Play', '/pause', '/play', '/resume', '/seek', '/send', '/status', '/stop', '/stream',
    ];

    /** CastClient suffix literal OCCURRENCES (12: /play and /key/Play appear twice). */
    private const CAST_SUFFIX_OCCURRENCES = 12;

    /** Per-command cast enumeration totals produced from the production enum. */
    private const CAST_PER_COMMAND = [
        'castTo' => 4,
        'devices' => 4,
        'pause' => 4,
        'resume' => 3,
        'seek' => 2,
        'status' => 4,
        'stop' => 3,
    ];

    /**
     * The ONLY tolerated client-literal-over-server-param collisions: Roku ECP
     * key commands ride `POST /api/v1/roku/devices/{id}/key/{keyName}` with the
     * literal `Play` key (pause toggle + resume). Any new such site (e.g. a
     * GET /media/match riding /media/{id}) fails this pin.
     */
    private const LITERAL_ON_PARAM = [
        'POST /api/v1/roku/devices/{P}/key/Play' => 2,
    ];

    /** Returned stream-serve URL literals (player fetches them outside the client). */
    private const SERVE_URLS = [
        ['src/Api/ApiClient.php', 'GET', '/api/v1/media/{P}/subtitles/external/{P}'],
    ];

    /**
     * Per-file count of `/api/v1`-carrying string tokens measured on the tree.
     * The sweep re-derives the accounted set from the scanner and asserts the
     * two agree 1:1 in BOTH directions (residual = scanner blind; phantom =
     * accounting drift).
     */
    private const SWEEP_TOKEN_COUNTS = [
        'src/Api/Admin/AdminClient.php' => 30,
        'src/Api/ApiClient.php' => 68,
        'src/Api/Cast/CastBackend.php' => 4,
        'src/Api/Hub/HubClient.php' => 3,
        'src/Api/SyncPlay/SyncPlayService.php' => 1,
        'src/Screen/RecommendationsScreen.php' => 1,
    ];

    /** Total server-manifest-compared sites (anchors − glue − hub + expansions + cast + serve-url). */
    private const TOTAL_COMPARED = 223;

    // ── manifest integrity ─────────────────────────────────────────────

    public function testVendoredManifestIsTheContractsArtifactByteIdentical(): void
    {
        $raw = (string) file_get_contents(self::MANIFEST_PATH);
        self::assertSame(self::EXPECTED_MD5, md5($raw), self::GATE_ID . ': vendored manifest drifted from contracts@2250def2');

        $manifest = self::manifest();
        self::assertSame(self::EXPECTED_SERVER_SHA, $manifest['provenance']['serverSha']);
        self::assertSame(400, $manifest['provenance']['total']);
        self::assertSame(400, count($manifest['routes']));
        self::assertSame('scripts/generate-server-route-manifest.mjs', $manifest['provenance']['generator']);

        $unique = [];
        foreach ($manifest['routes'] as [$m, $p]) {
            $unique["{$m} {$p}"] = true;
        }
        self::assertCount(400, $unique, 'manifest tuples must be unique');
    }

    // ── the gate ───────────────────────────────────────────────────────

    public function testAnchorScanMatchesPerClassPins(): void
    {
        $scan = self::scan();
        $perFile = [];
        foreach ($scan['sites'] as $s) {
            $perFile[$s['file']] = ($perFile[$s['file']] ?? 0) + 1;
        }
        ksort($perFile);
        self::assertSame(self::PER_CLASS_ANCHORS, $perFile, 'per-class anchor site counts moved');

        $urlSites = [];
        foreach ($scan['sites'] as $s) {
            if ($s['kind'] === 'url') {
                $urlSites[] = [$s['file'], $s['verb'], $s['path']];
            }
        }
        self::assertSame(self::URL_SITES, $urlSites, 'url()-anchored sites moved');

        $composition = [];
        foreach ($scan['castCalls'] as $c) {
            $composition[$c['file']] = ($composition[$c['file']] ?? 0) + 1;
        }
        ksort($composition);
        self::assertSame(self::COMPOSITION_SITES, $composition, 'variable-receiver composition sites moved');
    }

    public function testSuffixHelperExpansionsArePinnedAndServed(): void
    {
        $scan = self::scan();
        $perHelper = [];
        foreach ($scan['expansions'] as $e) {
            $perHelper[$e['helper']] = ($perHelper[$e['helper']] ?? 0) + 1;
        }
        ksort($perHelper);
        self::assertSame(self::EXPANSIONS_PER_HELPER, $perHelper, 'suffix-helper expansions moved');

        $glue = [];
        foreach ($scan['sites'] as $s) {
            if ($s['glue']) {
                $glue[] = $s['helper'];
            }
        }
        sort($glue);
        $expectedGlue = array_keys(self::EXPANSIONS_PER_HELPER);
        sort($expectedGlue);
        self::assertSame($expectedGlue, $glue, 'glue sites must mirror the expansion helpers');
    }

    public function testCastSuffixLiteralsArePinned(): void
    {
        $scan = self::scan();
        self::assertSame(
            self::CAST_SUFFIX_OCCURRENCES,
            count($scan['castSuffixes']),
            'CastClient suffix-literal occurrences moved'
        );
        $distinct = array_values(array_unique($scan['castSuffixes']));
        self::assertSame(self::CAST_SUFFIXES, $distinct, 'CastClient gained/lost a literal suffix — enumerate it here');
    }

    public function testCastSurfaceEnumeratedFromProductionEnumIsServed(): void
    {
        $scan = self::scan();
        $perCmd = [];
        foreach ($scan['cast'] as $c) {
            $perCmd[$c['cmd']] = ($perCmd[$c['cmd']] ?? 0) + 1;
        }
        ksort($perCmd);
        self::assertSame(self::CAST_PER_COMMAND, $perCmd, 'production enum shape changed — re-check capability guards');
        self::assertSame(24, count($scan['cast']));

        $unserved = self::unservedAmong($scan['cast']);
        self::assertSame([], $unserved, 'cast enumeration produced an unserved tuple');
    }

    public function testEveryServerSiteIsTupleExactAgainstTheManifest(): void
    {
        $scan = self::scan();
        self::assertGreaterThan(0, count($scan['compared']), 'non-vacuity: the scan must see request sites');
        self::assertCount(self::TOTAL_COMPARED, $scan['compared'], 'total compared site count moved');

        $unserved = self::unservedAmong($scan['compared']);
        $lines = [];
        foreach ($unserved as $u) {
            $lines[] = "{$u['file']} {$u['verb']} {$u['path']}";
        }
        self::assertSame([], $lines, self::GATE_ID . ': unserved rails reached the wire');
    }

    public function testLiteralOnParamCollisionsArePinnedExactly(): void
    {
        $scan = self::scan();
        $seen = [];
        foreach ($scan['compared'] as $s) {
            $matches = self::matchRoutes($s['verb'], $s['path']);
            self::assertNotSame([], $matches, "site {$s['path']} must be served before collision analysis");
            $positions = self::literalOnParamPositions($s['path'], $matches);
            if ($positions !== []) {
                $key = "{$s['verb']} {$s['path']}";
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }
        ksort($seen);
        self::assertSame(
            self::LITERAL_ON_PARAM,
            $seen,
            'a client literal landed on a server param route outside the pinned Roku ECP keys — '
            . 'this is the /media/match collision class: the server routes it to a param handler that 404s'
        );
    }

    public function testHubNegativePartitionIsPerPathAndAbsentFromServerManifest(): void
    {
        $scan = self::scan();
        $found = [];
        foreach ($scan['hubs'] as $h) {
            $found[] = [$h['file'], $h['verb'], $h['path']];
        }
        self::assertSame(
            GateScanner::HUB_TUPLES,
            $found,
            'the hub-addressed partition moved — it must stay per-PATH (shared transport)'
        );

        foreach (GateScanner::HUB_TUPLES as [, $verb, $path]) {
            $shadow = self::matchRoutes(null, $path);
            self::assertSame([], $shadow, "hub rail {$path} unexpectedly matches a SERVER route under some method");
        }
        self::assertGreaterThanOrEqual(10, count($scan['hubs']));
    }

    public function testSyncplayWebsocketIsPinnedOutOfTheHttpManifest(): void
    {
        $scan = self::scan();
        self::assertCount(1, $scan['ws']);
        $ws = $scan['ws'][0];
        self::assertSame('/api/v1/syncplay/{P}', $ws['path']);
        self::assertSame(1, $ws['occurrences'], "the ws URL literal must occur exactly once ({$ws['file']})");
        self::assertSame(6, $ws['frames'], 'ws frame sends (terminal, no HTTP literal) must stay 6');

        $shadow = self::matchRoutes(null, $ws['path']);
        $shadowKeys = array_map(static fn (array $r): string => "{$r[0]} {$r[1]}", $shadow);
        self::assertSame(
            [],
            $shadowKeys,
            'the syncplay ROOM socket must not collide with a server HTTP rail (groups rails are 5 segments)'
        );
    }

    public function testServeUrlLiteralsArePinnedAndServed(): void
    {
        $scan = self::scan();
        $seen = [];
        foreach ($scan['serveUrls'] as $u) {
            $seen[] = [$u['file'], $u['verb'], $u['path']];
        }
        self::assertSame(self::SERVE_URLS, $seen, 'returned serve-URL literals moved');
        self::assertSame([], self::unservedAmong($scan['serveUrls']), 'a serve-URL literal is unserved');
    }

    public function testBlindnessSweepIsOneToOnePerFile(): void
    {
        $scan = self::scan();
        $counts = [];
        foreach ($scan['sweep'] as $file => $row) {
            $counts[$file] = $row['tokens'];
            self::assertSame([], $row['residual'], "scanner went blind in {$file}: unaccounted /api/v1 tokens");
            self::assertSame([], $row['phantom'], "accounting drifted in {$file}: consumed tokens that do not exist");
        }
        ksort($counts);
        self::assertSame(self::SWEEP_TOKEN_COUNTS, $counts, 'per-file /api/v1 token inventory moved');
    }

    public function testExactCompareControlsAndPlantedProbeTripTheGate(): void
    {
        // Non-vacuity control: the membership function itself must be exact.
        self::assertTrue(self::isServed('GET', '/api/v1/media/{P}'), 'sanity: media detail rail is served');
        self::assertFalse(self::isServed('GET', '/api/v1/media/{P}/not-registered'), 'sibling-wildcard absorption must fail');
        self::assertFalse(self::isServed('GET', '/api/v1/s405-planted-probe'), 'planted probe must never read served');
        self::assertFalse(self::isServed('POST', '/api/v1/admin/services'), 'the removed aggregate rail must stay unserved');
        // The /media/match collision class: a loose compare alone WOULD pass it
        // (server GET /media/{id} shadows it with id='match'); the literal-on-
        // param pin test is what turns that into a gate failure. Demonstrate
        // the detection half here: it must flag positions, not pass silently.
        $routes = self::manifest()['routes'];
        $collision = GateScanner::literalOnParam(
            '/api/v1/media/match',
            GateScanner::matchRoutes($routes, 'GET', '/api/v1/media/match')
        );
        self::assertNotSame(
            [],
            $collision,
            'the removed review-list rail must be flagged as a literal-on-param collision'
        );
        self::assertTrue(self::isServed('DELETE', '/api/v1/media/{P}/rating'), 'the S405 repointed singular rail is served');
        self::assertTrue(self::isServed('GET', '/api/v1/users/me/settings'), 'the S405 repointed settings rail is served');
        self::assertTrue(
            self::isServed('GET', '/api/v1/admin/libraries/{P}/duplicates'),
            'the S405 admin-prefixed duplicates rail is served'
        );

        // And the full filter pipeline must flag it if a planted site entered the scan.
        $planted = [['file' => 'src/PLANTED.php', 'verb' => 'GET', 'path' => '/api/v1/s405-planted-probe']];
        self::assertSame($planted, self::unservedAmong($planted), 'unserved detection must not silently pass a phantom');
    }

    // ── machinery ──────────────────────────────────────────────────────

    /**
     * @return array{routes: list<array{0:string,1:string}>}
     */
    private static function manifest(): array
    {
        /** @var array{provenance: array<string,mixed>, routes: list<array{0:string,1:string}>} $m */
        $m = json_decode((string) file_get_contents(self::MANIFEST_PATH), true, 512, JSON_THROW_ON_ERROR);
        return $m;
    }

    /**
     * @return array{sites: list<array<string,mixed>>, expansions: list<array<string,mixed>>,
     *   castCalls: list<array<string,mixed>>, hubs: list<array<string,mixed>>,
     *   compared: list<array<string,mixed>>, cast: list<array<string,mixed>>,
     *   serveUrls: list<array<string,mixed>>, ws: list<array<string,mixed>>,
     *   sweep: array<string, array<string,mixed>>, castSuffixes: list<string>}
     */
    private static function scan(): array
    {
        $src = realpath(self::REPO_ROOT . '/src') ?: self::REPO_ROOT . '/src';
        $root = realpath(self::REPO_ROOT) ?: self::REPO_ROOT;
        return GateScanner::scan($src, $root);
    }

    /**
     * @param list<array<string,mixed>> $sites
     * @return list<array<string,mixed>>
     */
    private static function unservedAmong(array $sites): array
    {
        $out = [];
        foreach ($sites as $s) {
            if (self::matchRoutes($s['verb'], $s['path']) === []) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function matchRoutes(?string $verb, string $clientPath): array
    {
        return GateScanner::matchRoutes(self::manifest()['routes'], $verb, $clientPath);
    }

    private static function isServed(string $verb, string $clientPath): bool
    {
        return self::matchRoutes($verb, $clientPath) !== [];
    }

    /**
     * @param list<array{0:string,1:string}> $matches
     * @return list<int>
     */
    private static function literalOnParamPositions(string $clientPath, array $matches): array
    {
        return GateScanner::literalOnParam($clientPath, $matches);
    }
}

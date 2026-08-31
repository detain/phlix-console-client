<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Tests\Unit\Api;

use Phlix\Console\Api\Cast\CastBackend;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Token-level request-site scanner for the S405 route gate (see
 * {@see ServerRouteManifestGateTest} for the why; this file is the how).
 *
 * Reconstruction rules (S280-evidence design, PHP 8 aware):
 * - literal parts concatenated; `self::CONST` resolved from a per-file
 *   T_CONST harvest (unresolved const = fail fast, never silently skipped);
 * - rawurlencode(...)/$var/method results canonicalise to `{P}`;
 * - PHP 8 casts arrive as `(`/T_STRING/`)` (there is no T_CAST token);
 * - glue templates (a `{P}` embedded inside a segment) are suffix-helper
 *   sites and are replaced by expansions harvested from same-file
 *   `->helper(literal…)` callers;
 * - variable-receiver compositions in CastClient are the runtime enum
 *   surface, enumerated from the PRODUCTION CastBackend, never a fixture.
 *
 * Not a PHPUnit test class (no suffix match); autoloaded via composer's
 * dev PSR-4 map.
 */
final class GateScanner
{
    public const ANCHORS = ['authed', 'exchange', 'send', 'url', 'request'];

    /**
     * Hub-addressed sites asserted PER-PATH (console HubClient rides the shared
     * ApiClient — the base URL flips at runtime, so a per-FILE exclusion,
     * which tizen could afford for its hubRelay split, is WRONG for console).
     */
    public const HUB_TUPLES = [
        ['src/Api/ApiClient.php', 'GET', '/api/v1/me/servers'],
        ['src/Api/Hub/HubClient.php', 'GET', '/api/v1/me/shares'],
        ['src/Api/Hub/HubClient.php', 'GET', '/api/v1/me/invite-links'],
        ['src/Api/Hub/HubClient.php', 'POST', '/api/v1/me/invite-links'],
        ['src/Api/Hub/HubClient.php', 'DELETE', '/api/v1/me/invite-links/{P}'],
        ['src/Api/Hub/HubClient.php', 'GET', '/api/v1/me/federation/library-shares/outgoing'],
        ['src/Api/Hub/HubClient.php', 'GET', '/api/v1/me/federation/library-shares/incoming'],
        [
            'src/Api/Hub/HubClient.php', 'POST', '/api/v1/me/federation/library-shares/incoming/{P}/accept',
        ],
        [
            'src/Api/Hub/HubClient.php', 'POST', '/api/v1/me/federation/library-shares/incoming/{P}/reject',
        ],
        ['src/Api/Hub/HubClient.php', 'DELETE', '/api/v1/me/federation/library-shares/outgoing/{P}'],
    ];

    /**
     * @return array{sites: list<array<string,mixed>>, expansions: list<array<string,mixed>>,
     *   castCalls: list<array<string,mixed>>, hubs: list<array<string,mixed>>,
     *   compared: list<array<string,mixed>>, cast: list<array<string,mixed>>,
     *   serveUrls: list<array<string,mixed>>, ws: list<array<string,mixed>>,
     *   sweep: array<string, array<string,mixed>>, castSuffixes: list<string>}
     */
    public static function scan(string $srcDir, string $repoRoot): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        $sites = [];
        $castCalls = [];
        $castSuffixes = [];
        $constsByFile = [];
        $srcByFile = [];
        foreach ($files as $abs) {
            $rel = substr($abs, strlen($repoRoot) + 1);
            $code = (string) file_get_contents($abs);
            $srcByFile[$rel] = $code;
            $tokens = token_get_all($code);
            $constsByFile[$rel] = self::harvestConsts($tokens);
            $n = count($tokens);
            $currentFn = null;
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                if (is_array($t) && $t[0] === T_FUNCTION) {
                    $j = $i + 1;
                    while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }
                    $currentFn = $j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING ? $tokens[$j][1] : null;
                    continue;
                }
                if (str_contains($rel, 'CastClient.php') && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $val = self::unquote($t[1]);
                    if (str_starts_with($val, '/') && !str_contains($val, '/api/v1')) {
                        $castSuffixes[] = $val;
                    }
                }
                if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], self::ANCHORS, true)) {
                    continue;
                }
                $anchor = $t[1];
                $line = $t[2];
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION) {
                    continue;
                }
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j >= $n || $tokens[$j] !== '(') {
                    continue;
                }
                $p = $j + 1;
                if ($anchor === 'url') {
                    [$tpl, , $use] = self::parseExpr($tokens, $p, $constsByFile[$rel], $rel);
                    if (str_starts_with($tpl, '/api/v1')) {
                        $sites[] = self::site($rel, $line, 'GET', $tpl, $currentFn, $use, 'url');
                    }
                    continue;
                }
                while ($p < $n && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                    $p++;
                }
                if ($p >= $n || !is_array($tokens[$p]) || $tokens[$p][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $verb = strtoupper(self::unquote($tokens[$p][1]));
                if (!in_array($verb, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                $p++;
                while ($p < $n && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                    $p++;
                }
                if ($p >= $n || $tokens[$p] !== ',') {
                    continue;
                }
                $p++;
                while ($p < $n && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                    $p++;
                }
                [$tpl, , $use] = self::parseExpr($tokens, $p, $constsByFile[$rel], $rel);
                if ($tpl === '') {
                    continue;
                }
                if (str_starts_with($tpl, '/api/v1')) {
                    $sites[] = self::site($rel, $line, $verb, $tpl, $currentFn, $use, 'anchor');
                    continue;
                }
                if (preg_match('/^(\{P\})+$/', $tpl) === 1) {
                    $castCalls[] = ['file' => $rel, 'line' => $line, 'verb' => $verb];
                    continue;
                }
                if (str_starts_with($tpl, '{P}') && str_contains($rel, 'CastClient.php')) {
                    $castCalls[] = ['file' => $rel, 'line' => $line, 'verb' => $verb];
                    continue;
                }
                throw new RuntimeException("unclassified request site {$rel}:{$line} {$verb} {$tpl}");
            }
        }
        sort($castSuffixes);

        $expansions = self::expandGlueSites($sites, $srcByFile, $constsByFile);

        [$hubs, $compared] = self::partition($sites, $expansions);

        $cast = self::castSites();
        $compared = array_merge($compared, $cast);

        $serveUrls = self::serveUrls($srcByFile);
        $compared = array_merge($compared, $serveUrls);

        $ws = self::ws($srcByFile);
        $sweep = self::sweep($srcByFile, $sites, $expansions, $serveUrls, $constsByFile);

        return compact('sites', 'expansions', 'castCalls', 'hubs', 'compared', 'cast', 'serveUrls', 'ws', 'sweep', 'castSuffixes');
    }

    /**
     * @param list<array{0:string,1:string}> $routes
     * @return list<array{0:string,1:string}>
     */
    public static function matchRoutes(array $routes, ?string $verb, string $clientPath): array
    {
        $norm = self::normalize($clientPath);
        $out = [];
        foreach ($routes as [$m, $sp]) {
            if ($verb !== null && $m !== $verb) {
                continue;
            }
            if (self::templateMatches($sp, $norm)) {
                $out[] = [$m, $sp];
            }
        }
        return $out;
    }

    /**
     * Segment positions where a client LITERAL matched a server PARAM.
     *
     * @param list<array{0:string,1:string}> $matches
     * @return list<int>
     */
    public static function literalOnParam(string $clientPath, array $matches): array
    {
        $best = null;
        foreach ($matches as [, $sp]) {
            $a = explode('/', $sp);
            $b = explode('/', self::normalize($clientPath));
            $pos = [];
            foreach ($a as $i => $seg) {
                if (self::isParam($seg) && !self::isParam($b[$i])) {
                    $pos[] = $i;
                }
            }
            if ($best === null || count($pos) < count($best)) {
                $best = $pos;
            }
        }
        return $best ?? [];
    }

    public static function templateMatches(string $serverTemplate, string $clientTemplate): bool
    {
        $a = explode('/', $serverTemplate);
        $b = explode('/', self::normalize($clientTemplate));
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $seg) {
            if (self::isParam($seg)) {
                continue;
            }
            if (self::isParam($b[$i]) || $b[$i] !== $seg) {
                return false;
            }
        }
        return true;
    }

    public static function isParam(string $seg): bool
    {
        return preg_match('/^\{[^{}]*\}$/', $seg) === 1;
    }

    public static function normalize(string $p): string
    {
        $p = explode('?', $p)[0];
        if ($p !== '/' && str_ends_with($p, '/')) {
            $p = rtrim($p, '/');
        }
        return $p;
    }

    /**
     * @param list<array<string,mixed>> $sites
     * @param array<string, string> $srcByFile
     * @param array<string, array<string, array{line:int, value:string}>> $constsByFile
     * @return list<array<string,mixed>>
     */
    private static function expandGlueSites(array $sites, array $srcByFile, array $constsByFile): array
    {
        $expansions = [];
        foreach ($sites as $s) {
            if (!$s['glue']) {
                continue;
            }
            $helper = $s['helper'];
            if ($helper === null) {
                throw new RuntimeException("glue site without function scope: {$s['file']}:{$s['line']}");
            }
            $base = substr($s['path'], 0, -strlen('{P}'));
            $tokens = token_get_all($srcByFile[$s['file']]);
            $n = count($tokens);
            $found = 0;
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== $helper) {
                    continue;
                }
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if (
                    $prev < 0 || !is_array($tokens[$prev])
                    || !in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                ) {
                    continue;
                }
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j >= $n || $tokens[$j] !== '(') {
                    continue;
                }
                [$suffix, , $euse] = self::parseExpr($tokens, $j + 1, $constsByFile[$s['file']], $s['file']);
                if ($suffix === '' || $suffix === '{P}') {
                    throw new RuntimeException("caller of {$helper} passes a non-literal suffix at {$s['file']}:{$t[2]}");
                }
                $expansions[] = ['file' => $s['file'], 'line' => $t[2], 'verb' => $s['verb'],
                    'path' => $base . $suffix, 'kind' => 'expansion', 'glue' => false, 'helper' => $helper, 'use' => $euse];
                $found++;
            }
            if ($found === 0) {
                throw new RuntimeException("glue helper {$helper} has no literal callers (scanner blind?)");
            }
        }
        return $expansions;
    }

    /**
     * @param list<array<string,mixed>> $sites
     * @param list<array<string,mixed>> $expansions
     * @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>}
     */
    private static function partition(array $sites, array $expansions): array
    {
        $hubs = [];
        $compared = [];
        foreach (array_merge($sites, $expansions) as $s) {
            if (!empty($s['glue'])) {
                continue;
            }
            if (self::isHub($s['file'], $s['verb'], $s['path'])) {
                $hubs[] = $s;
                continue;
            }
            $compared[] = $s;
        }
        if (count($hubs) !== 10) {
            throw new RuntimeException('hub partition must locate exactly 10 pinned hub sites, found ' . count($hubs));
        }
        return [$hubs, $compared];
    }

    private static function isHub(string $file, string $verb, string $path): bool
    {
        foreach (self::HUB_TUPLES as [$f, $v, $p]) {
            if ($file === $f && $verb === $v && $path === $p) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enumerate the cast surface from the PRODUCTION enum (never a fixture).
     *
     * @return list<array<string,mixed>>
     */
    private static function castSites(): array
    {
        $file = 'src/Api/Cast/CastBackend.php';
        $out = [];
        foreach (CastBackend::cases() as $b) {
            $dev = str_replace('%7BP%7D', '{P}', $b->devicePath('{P}'));
            $out[] = ['file' => $file, 'cmd' => 'devices', 'verb' => 'GET', 'path' => $b->devicesPath(), 'kind' => 'cast'];
            $out[] = ['file' => $file, 'cmd' => 'status', 'verb' => 'GET', 'path' => $dev . '/status', 'kind' => 'cast'];
            $castSuffix = match ($b) {
                CastBackend::Chromecast => '/cast',
                CastBackend::Roku => '/send',
                CastBackend::AirPlay => '/stream',
                CastBackend::Dlna => '/play',
            };
            $out[] = ['file' => $file, 'cmd' => 'castTo', 'verb' => 'POST', 'path' => $dev . $castSuffix, 'kind' => 'cast'];
            $pauseSuffix = $b === CastBackend::Roku ? '/key/Play' : '/pause';
            $out[] = ['file' => $file, 'cmd' => 'pause', 'verb' => 'POST', 'path' => $dev . $pauseSuffix, 'kind' => 'cast'];
            if ($b->canResume()) {
                $resumeSuffix = match ($b) {
                    CastBackend::Chromecast => '/play',
                    CastBackend::Roku => '/key/Play',
                    default => '/resume',
                };
                $out[] = ['file' => $file, 'cmd' => 'resume', 'verb' => 'POST', 'path' => $dev . $resumeSuffix, 'kind' => 'cast'];
            }
            if ($b->canStop()) {
                $out[] = ['file' => $file, 'cmd' => 'stop', 'verb' => 'POST', 'path' => $dev . '/stop', 'kind' => 'cast'];
            }
            if ($b->canSeek()) {
                $out[] = ['file' => $file, 'cmd' => 'seek', 'verb' => 'POST', 'path' => $dev . '/seek', 'kind' => 'cast'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $srcByFile
     * @return list<array<string,mixed>>
     */
    private static function serveUrls(array $srcByFile): array
    {
        $out = [];
        foreach ($srcByFile as $file => $code) {
            if (!str_contains($file, 'ApiClient.php')) {
                continue;
            }
            foreach (explode("\n", $code) as $idx => $text) {
                $needle = "return '/api/v1/media/' . rawurlencode(\$mediaId) . '/subtitles/external/'";
                if (str_contains($text, $needle)) {
                    $out[] = ['file' => $file, 'line' => $idx + 1, 'verb' => 'GET',
                        'path' => '/api/v1/media/{P}/subtitles/external/{P}', 'kind' => 'serve-url', 'glue' => false];
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $srcByFile
     * @return list<array<string,mixed>>
     */
    private static function ws(array $srcByFile): array
    {
        $out = [];
        foreach ($srcByFile as $file => $code) {
            if (!str_contains($file, 'SyncPlayService.php')) {
                continue;
            }
            $frames = preg_match_all('/\$this->wsConnection\??->send\(/', $code);
            $occ = substr_count($code, "'/api/v1/syncplay/'");
            $out[] = ['file' => $file, 'occurrences' => $occ, 'frames' => $frames, 'path' => '/api/v1/syncplay/{P}'];
        }
        return $out;
    }

    /**
     * @param array<string, string> $srcByFile
     * @param list<array<string,mixed>> $sites
     * @param list<array<string,mixed>> $expansions
     * @param list<array<string,mixed>> $serveUrls
     * @param array<string, array<string, array{line:int, value:string}>> $constsByFile
     * @return array<string, array<string,mixed>>
     */
    private static function sweep(array $srcByFile, array $sites, array $expansions, array $serveUrls, array $constsByFile): array
    {
        $sweep = [];
        foreach ($srcByFile as $file => $code) {
            $toks = token_get_all($code);
            $lines = [];
            foreach ($toks as $t) {
                if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($t[1], '/api/v1')) {
                    $lines[] = $t[2];
                }
            }
            if ($lines === []) {
                continue;
            }
            sort($lines);
            $accounted = [];
            foreach ($sites as $s) {
                if ($s['file'] === $file && $s['use']['headLiteralLine'] !== null) {
                    $accounted[] = $s['use']['headLiteralLine'];
                }
            }
            $usedConsts = [];
            foreach ($sites as $s) {
                if ($s['file'] === $file) {
                    foreach ($s['use']['constNames'] as $cn) {
                        $usedConsts[$constsByFile[$file][$cn]['line']] = true;
                    }
                }
            }
            foreach (array_keys($usedConsts) as $ln) {
                $accounted[] = $ln;
            }
            foreach ($expansions as $e) {
                if ($e['file'] === $file && $e['use']['headLiteralLine'] !== null) {
                    $accounted[] = $e['use']['headLiteralLine'];
                }
            }
            foreach ($serveUrls as $u) {
                if ($u['file'] === $file) {
                    $accounted[] = $u['line'];
                }
            }
            if (str_contains($file, 'SyncPlayService.php') || str_contains($file, 'CastBackend.php')) {
                foreach ($lines as $ln) {
                    $accounted[] = $ln;
                }
            }
            $accCounts = [];
            foreach ($accounted as $ln) {
                $accCounts[$ln] = ($accCounts[$ln] ?? 0) + 1;
            }
            $tokCounts = [];
            foreach ($lines as $ln) {
                $tokCounts[$ln] = ($tokCounts[$ln] ?? 0) + 1;
            }
            $residual = array_keys(array_diff($tokCounts, $accCounts));
            $phantom = array_keys(array_diff($accCounts, $tokCounts));
            $sweep[$file] = ['tokens' => count($lines), 'accounted' => count($accounted),
                'residual' => $residual, 'phantom' => $phantom];
        }
        return $sweep;
    }

    /**
     * @param array{int,string,int}|string $use
     * @return array<string,mixed>
     */
    private static function site(string $file, int $line, string $verb, string $tpl, ?string $helper, array $use, string $kind): array
    {
        return ['file' => $file, 'line' => $line, 'verb' => $verb, 'path' => $tpl,
            'kind' => $kind, 'glue' => self::isGlue($tpl), 'helper' => $helper, 'use' => $use];
    }

    private static function isGlue(string $tpl): bool
    {
        foreach (explode('/', $tpl) as $seg) {
            if ($seg !== '{P}' && str_contains($seg, '{P}')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, array{line:int, value:string}>
     */
    private static function harvestConsts(array $tokens): array
    {
        $consts = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_CONST) {
                continue;
            }
            $j = $i + 1;
            $name = null;
            while ($j < $n) {
                $tj = $tokens[$j];
                if (is_array($tj) && $tj[0] === T_STRING) {
                    $name = $tj[1];
                }
                if (!is_array($tj) && $tj === '=') {
                    break;
                }
                $j++;
            }
            $k = $j + 1;
            while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                $k++;
            }
            if ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING && $name !== null) {
                $consts[$name] = ['line' => $tokens[$k][2], 'value' => self::unquote($tokens[$k][1])];
            }
        }
        return $consts;
    }

    /**
     * @param list<array{int,string,int}|string> $tokens
     * @param array<string, array{line:int, value:string}> $consts
     * @return array{0:string, 1:int, 2:array{headLiteralLine:?int, constNames:list<string>}}
     */
    private static function parseExpr(array $tokens, int $p, array $consts, string $file): array
    {
        $n = count($tokens);
        $tpl = '';
        $headLiteralLine = null;
        $constNames = [];
        $depth = 0;
        $stopChars = [',', ')', ';'];
        while ($p < $n) {
            while ($p < $n && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                $p++;
            }
            if ($p >= $n) {
                break;
            }
            $t = $tokens[$p];
            if (!is_array($t) && in_array($t, $stopChars, true) && $depth === 0) {
                break;
            }
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                if ($tpl === '' && str_contains(self::unquote($t[1]), '/api/v1')) {
                    $headLiteralLine = $t[2];
                }
                $tpl .= self::unquote($t[1]);
                $p++;
                continue;
            }
            if (!is_array($t) && $t === '.') {
                $p++;
                continue;
            }
            if (!is_array($t) && $t === '(') {
                $j = $p + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if (
                    $j + 1 < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING
                    && in_array(strtolower((string) $tokens[$j][1]), ['string', 'int', 'integer', 'float', 'double', 'bool', 'boolean', 'object', 'array'], true)
                ) {
                    $k = $j + 1;
                    while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        $k++;
                    }
                    if ($k < $n && $tokens[$k] === ')') {
                        $p = $k + 1;
                        continue;
                    }
                }
                $depth++;
                $p++;
                continue;
            }
            if (!is_array($t) && $t === ')') {
                $depth--;
                $p++;
                continue;
            }
            if (is_array($t) && ($t[0] === T_STRING || $t[0] === T_STATIC) && in_array(strtolower((string) $t[1]), ['self', 'static'], true)) {
                $j = $p + 1;
                $skip2 = function () use (&$j, $tokens, $n): void {
                    while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }
                };
                $skip2();
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                    $j++;
                    $skip2();
                    if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $cname = $tokens[$j][1];
                        $j++;
                        $skip2();
                        if ($j < $n && $tokens[$j] === '(') {
                            throw new RuntimeException("method-shaped self::{$cname}() path part not supported in {$file}:{$t[2]}");
                        }
                        if (!array_key_exists($cname, $consts)) {
                            throw new RuntimeException("unresolved const self::{$cname} in {$file}:{$t[2]}");
                        }
                        $constNames[] = $cname;
                        $tpl .= $consts[$cname]['value'];
                        $p = $j;
                        continue;
                    }
                }
                throw new RuntimeException("unparsed self/static shape in {$file}:{$t[2]}");
            }
            if (is_array($t) && $t[0] === T_STRING) {
                $j = $p + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j < $n && $tokens[$j] === '(') {
                    $d = 1;
                    $k = $j + 1;
                    while ($k < $n && $d > 0) {
                        if ($tokens[$k] === '(') {
                            $d++;
                        } elseif ($tokens[$k] === ')') {
                            $d--;
                        }
                        $k++;
                    }
                    $tpl .= '{P}';
                    $p = $k;
                    continue;
                }
                $tpl .= '{P}';
                $p++;
                continue;
            }
            if (is_array($t) && $t[0] === T_VARIABLE) {
                $tpl .= '{P}';
                $p++;
                continue;
            }
            if (is_array($t) && $t[0] === T_ENCAPSED_AND_WHITESPACE) {
                if ($tpl === '' && str_contains($t[1], '/api/v1')) {
                    $headLiteralLine = $t[2];
                }
                $tpl .= self::unquote('"' . $t[1] . '"');
                $p++;
                continue;
            }
            if (is_array($t) && ($t[0] === T_DOLLAR_OPEN_CURLY_BRACES || $t[0] === T_CURLY_OPEN)) {
                $d = 1;
                $p++;
                while ($p < $n && $d > 0) {
                    if ($tokens[$p] === '{') {
                        $d++;
                    } elseif ($tokens[$p] === '}') {
                        $d--;
                    }
                    $p++;
                }
                $tpl .= '{P}';
                continue;
            }
            if (is_array($t) && ($t[0] === T_OBJECT_OPERATOR || $t[0] === T_NULLSAFE_OBJECT_OPERATOR)) {
                $p++;
                continue;
            }
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT || $t[0] === T_INLINE_HTML)) {
                $p++;
                continue;
            }
            break;
        }
        return [$tpl, $p, ['headLiteralLine' => $headLiteralLine, 'constNames' => $constNames]];
    }

    private static function unquote(string $lit): string
    {
        $q = $lit[0];
        $v = substr($lit, 1, -1);
        return $q === '"' ? strtr($v, ['\\"' => '"', '\\\\' => '\\', '\\$' => '$']) : str_replace("''", "'", $v);
    }
}

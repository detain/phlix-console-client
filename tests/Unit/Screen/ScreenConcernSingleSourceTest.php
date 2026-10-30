<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * M-CONSOLE-37 single-source law for the byte-identical screen helper families.
 *
 * Two guards over a token census of src/Screen/ (visibility + signature + body,
 * whitespace and comments normalized away — the exact identity a "copy the fix
 * across all N sites" patch preserves, and the exact drift this repo keeps
 * re-paying: URL-join edge cases, selection wrap-around, resize bounds):
 *
 *  1. CANONICAL: the four families extracted in the M37 batch (resizedTo x37,
 *     resolveUrl x11, selectPrev x9, selectNext x5) must exist EXACTLY ONCE, in
 *     their trait file. A screen re-adding a local copy — byte-identical or
 *     "just slightly different from" the trait (same normalized tokens) — is a
 *     duplicate definition and goes red here, not in the next audit.
 *  2. RATCHET: the remaining duplicate families are pinned by (name, hash) →
 *     count in DUPLICATE_BASELINE below. Any NEW duplicate family, any count
 *     growth, or any count change (a partial peel without re-pin) is red. The
 *     four withError state-machine shapes (6 sub-families), the onActionDone
 *     message-typed sextet and the rest are known debt with named follow-up
 *     owners — consolidation lanes may only rotate the baseline DOWNWARD, from
 *     a measured red, exactly like the estate census pins.
 *
 * Red-on-parent law: on the pre-M37 tree, guard 1 fails (37 copies, no trait
 * files) and guard 2 fails (pinned map post-dates the extraction).
 */
final class ScreenConcernSingleSourceTest extends TestCase
{
    /**
     * The families the M37 batch single-sourced: method => [trait file, census key].
     * The census key is name . ':' . 12-hex md5 over (visibility + signature + body)
     * token text; regenerate with the walker below — do not hand-edit hashes.
     *
     * @var array<string, array{trait: string, key: string}>
     */
    private const CANONICAL = [
        'resizedTo' => ['trait' => 'ResizesTo.php', 'key' => 'resizedTo:a0e140ed4091'],
        'resolveUrl' => ['trait' => 'ResolvesUrls.php', 'key' => 'resolveUrl:628aa06fbe13'],
        'selectPrev' => ['trait' => 'SelectsPrevious.php', 'key' => 'selectPrev:a62b591745b3'],
        'selectNext' => ['trait' => 'SelectsNext.php', 'key' => 'selectNext:ac421f261e67'],
    ];

    /**
     * Duplicate (name, signature, body) families in src/Screen/ NOT yet single-
     * sourced (each key is name . ':' . 12-hex; count is the number of classes
     * carrying the byte-identical definition — the trait files themselves count
     * once and single definitions are absent by construction).
     *
     * @var array<string, int>
     */
    private const DUPLICATE_BASELINE = [
        '__construct:57a5d6e63f58' => 2,
        '__construct:7301c5ce50ce' => 4,
        '__construct:9c6e4b19ba43' => 2,
        '__construct:b26a51baffce' => 16,
        'afterGridChange:a62bcdf89148' => 2,
        'albumsByIndex:4243b72bcb43' => 2,
        'back:e9beed0e694f' => 2,
        'body:d42ac500ba2c' => 4,
        'cancelPending:d8b7acf66da9' => 2,
        'closeEdit:3d3de328ffc8' => 2,
        'closeForm:d34a890eb71f' => 3,
        'coerce:a822c509e3a0' => 2,
        'coerceError:596e227a29ed' => 2,
        'coerceJson:07adebc467f8' => 2,
        'columnWidth:484739ee9582' => 2,
        'create:a512a4f206d7' => 2,
        'crumbLabel:a26cf16aa513' => 3,
        'crumbLabel:d5115694604e' => 3,
        'editingKey:c8c0a39d4bcf' => 2,
        'entries:3e32494a5a8d' => 2,
        'error:3beed84b7ad0' => 37,
        'fetchAhead:fee10b3c9dfb' => 4,
        'fetchFailed:51d1517582bd' => 2,
        'formError:dec963054647' => 2,
        'grid:fb0b34ba4cae' => 5,
        'handleKey:244bd4b60486' => 2,
        'handleKey:51a5114f65b1' => 2,
        'handleKey:646c4f083a6f' => 3,
        'handleKey:ebe97b2a3954' => 2,
        'handleKey:f4e2417c460b' => 2,
        'hasHero:1152db42ad5e' => 2,
        'heroPlaceholder:24a38261873b' => 2,
        'humanBytes:c7e662758ab8' => 3,
        'idle:ad3349890c3a' => 2,
        'idle:bb3eb9d21a13' => 5,
        'init:165595781cec' => 6,
        'init:604dc9070fd5' => 2,
        'init:72aaaf30429a' => 2,
        'init:bb9c6c829ec6' => 5,
        'init:bf44542cbb17' => 4,
        'init:d6e459fc085f' => 20,
        'initialWindowEnd:5344bef7c76a' => 3,
        'initialWindowEnd:a846b8a5acdf' => 4,
        'isBusy:6ac4254d769d' => 11,
        'isCreating:b364a5cd3ed2' => 2,
        'isEditing:4b72f14022a8' => 2,
        'isEditing:7de7a84db62b' => 2,
        'isLoaded:4f7293604f52' => 24,
        'isLoading:0b9adf92891c' => 9,
        'isLoading:397ff32d7077' => 3,
        'items:149b6d301cf3' => 5,
        'loadCover:4624ad4adcaa' => 2,
        'loadVisibleCovers:b1fc5c5e7cbc' => 2,
        'moveSelection:0f67a05eea92' => 2,
        'name:35553c293466' => 3,
        'onPoster:010cc5be5b5e' => 3,
        'onRange:445ca1d5775a' => 2,
        'onRange:6cee06f3da76' => 2,
        'onResize:b6f335f72dd2' => 3,
        'openCreate:bc4e36b2a15d' => 2,
        'openSelected:c734f547b2f3' => 3,
        'pollEpoch:ee834455d131' => 2,
        'query:ccb55d034c21' => 2,
        'refresh:0e361c8648b5' => 3,
        'reloading:89ed05df1dd9' => 3,
        'reloading:a31ea43d3271' => 4,
        'renderItem:ef5d5e4e1161' => 2,
        'selectedAlbum:33302c7a0a82' => 2,
        'selectedIndex:47e270ff73c1' => 18,
        'selectedIndex:fa97ddbb435c' => 9,
        'statusLine:deaf070d4e3d' => 2,
        'subscriptions:2182a203523a' => 2,
        'teardown:145ee1b308bc' => 5,
        'theme:61420ee16bc0' => 2,
        'total:86673e3933c3' => 3,
        'update:1bbbc8158a55' => 2,
        'update:b40497285a79' => 2,
        'updateForm:4d71c619238d' => 2,
        'view:0f8763f5dde2' => 3,
        'view:1a5a675a1be7' => 2,
        'view:74abae5c3026' => 2,
        'viewportCols:af50dbe7fc01' => 5,
        'viewportRows:6c964e2db665' => 3,
        'viewportRows:7619a6bc8349' => 2,
        'viewportRows:7d2dd319cbef' => 4,
        'viewportRows:9330f2634e3d' => 5,
        'viewportRows:ce5064a54ecb' => 6,
        'withCrumbs:1c62c2ce3e24' => 46,
        'withCrumbs:2913492d87d3' => 2,
        'withError:41a7477e3aeb' => 9,
        'withError:5a4c9bf589c3' => 5,
        'withError:639c23cc173c' => 5,
        'withError:8d3aaa6a1ae5' => 2,
        'withError:deec2cb39eeb' => 8,
        'withError:ea1ba9d4d04a' => 4,
        'withGrid:618a99b61c5d' => 4,
        'withHero:dcd4d0c63d40' => 2,
        'withSelectedIndex:415180d2cb34' => 4,
        'withSelectedIndex:e2cbfac9d35f' => 5,
        'withTyped:5b9f56916867' => 2,
        'working:0ccdf20fbb06' => 2,
        'working:2574aa17e123' => 5,
        'working:c86957a40a3f' => 2,
    ];

    public function testTheExtractedFamiliesAreDefinedExactlyOnceInTheTraits(): void
    {
        $census = self::census();

        foreach (self::CANONICAL as $method => $pin) {
            $sites = $census[$pin['key']] ?? [];
            $files = array_map(static fn(array $s): string => $s[0], $sites);
            self::assertSame(
                [$pin['trait']],
                $files,
                "src/Screen method {$method} must be defined exactly once, in {$pin['trait']} — "
                . 'sites seen: ' . implode(', ', array_map(static fn(array $s): string => $s[0] . ':' . $s[1], $sites))
                . ' (move call sites to `use ' . substr($pin['trait'], 0, -4) . ';` instead of a local copy)',
            );
        }
    }

    public function testEveryCanonicalTraitFileExistsAndTheCensusSeesItsMethod(): void
    {
        foreach (self::CANONICAL as $method => $pin) {
            $path = self::screenDir() . '/' . $pin['trait'];
            self::assertFileExists($path, "the {$method} trait vanished — the screens lost their helper");
            self::assertStringContainsString(
                'function ' . $method . '(',
                (string) file_get_contents($path),
                $pin['trait'] . ' no longer declares ' . $method . '()',
            );
        }
    }

    public function testTheDuplicateFamilyRatchetHolds(): void
    {
        $current = [];
        foreach (self::census() as $key => $sites) {
            if (count($sites) >= 2) {
                $current[$key] = count($sites);
            }
        }

        $new = array_diff_key($current, self::DUPLICATE_BASELINE);
        self::assertSame(
            [],
            $new,
            'new byte-identical copy-paste family/families in src/Screen (extract to a concern trait, '
            . 'or move the call sites to an existing one): '
            . implode(', ', array_map(static fn(string $k): string => $k . ' x' . $current[$k], array_keys($new))),
        );

        $vanished = array_diff_key(self::DUPLICATE_BASELINE, $current);
        self::assertSame(
            [],
            $vanished,
            'pinned duplicate families dropped out of the census without a re-pin of DUPLICATE_BASELINE '
            . '(a consolidation that also rotated the canonical pins needs the baseline regenerated '
            . 'from the measured state): ' . implode(', ', array_keys($vanished)),
        );

        foreach (self::DUPLICATE_BASELINE as $key => $count) {
            self::assertArrayHasKey($key, $current);
            self::assertSame(
                $count,
                $current[$key],
                "family {$key} count drifted (pinned {$count}, seen {$current[$key]}) — growth is the exact "
                . 'failure class M-CONSOLE-37 exists to stop; a downward count means a partial peel: '
                . 'finish the consolidation into the traits or fold the copies back, then re-pin',
            );
        }
    }

    /**
     * Full method census of src/Screen/*.php.
     *
     * @return array<string, list<array{0: string, 1: int}>> key => sites (file, line)
     */
    private static function census(): array
    {
        $fams = [];
        $dir = self::screenDir();
        if (!is_dir($dir)) {
            throw new RuntimeException('single-source guard expects ' . $dir);
        }
        $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
        $files = [];
        foreach (new RecursiveIteratorIterator($it) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        self::assertNotEmptyForGuard($files);

        foreach ($files as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                if (!is_array($t) || $t[0] !== T_FUNCTION) {
                    continue;
                }
                $name = null;
                $nameIdx = null;
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $name = $tokens[$j][1];
                        $nameIdx = $j;
                    }
                    break;
                }
                if ($name === null) {
                    continue;
                }
                $brace = null;
                for ($j = $nameIdx + 1; $j < $n; $j++) {
                    if ($tokens[$j] === '{') {
                        $brace = $j;
                        break;
                    }
                    if ($tokens[$j] === ';') {
                        break;
                    }
                }
                if ($brace === null) {
                    continue;
                }
                $depth = 0;
                $end = null;
                for ($j = $brace; $j < $n; $j++) {
                    $tk = $tokens[$j];
                    if ($tk === '{') {
                        $depth++;
                    } elseif ($tk === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $j;
                            break;
                        }
                    }
                }
                $bodyN = self::normTokens($tokens, $brace + 1, (int) $end);
                $sigN = self::normTokens($tokens, $i, $brace);
                $vis = '';
                for ($j = $i - 1; $j >= 0; $j--) {
                    $tk = $tokens[$j];
                    if (is_array($tk) && $tk[0] === T_WHITESPACE) {
                        continue;
                    }
                    if (is_array($tk) && in_array($tk[0], [T_PRIVATE, T_PUBLIC, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT], true)) {
                        $vis = strtolower($tk[1]) . ' ' . $vis;
                        continue;
                    }
                    break;
                }
                $key = $name . ':' . substr(md5($vis . $sigN . $bodyN), 0, 12);
                $fams[$key][] = [basename($file), $t[2]];
            }
        }

        return $fams;
    }

    /**
     * @param list<array{0: mixed}|string> $tokens
     */
    private static function normTokens(array $tokens, int $from, int $to): string
    {
        $out = '';
        for ($j = $from; $j < $to; $j++) {
            $tk = $tokens[$j];
            if (is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($tk) ? $tk[1] : $tk;
        }

        return $out;
    }

    private static function screenDir(): string
    {
        return dirname(__DIR__, 3) . '/src/Screen';
    }


    /**
     * @param list<string> $files
     */
    private static function assertNotEmptyForGuard(array $files): void
    {
        if ($files === []) {
            throw new RuntimeException('src/Screen/ discovery broke — the guard would pass vacuously');
        }
    }
}

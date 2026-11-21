<?php

declare(strict_types=1);

/**
 * generate-channel-surf — "Phlix" on a cathode-ray set: TV static bursts and
 * rapid channel flips (muted SMPTE bars → crosshatch test card → teletext
 * page), the wordmark ghosting up through the snow, a rolling-horizon
 * interference band sweeping the screen, then the picture LOCKS on a clean
 * warm-amber Phlix with the CH bug settling to "CH 01".
 *
 * Pipeline: hand-built cell grid (72x12) per frame → lkEncode('tc') →
 * lkAnim(frames, static). Files are written by this generator directly into
 * its own directory (no logos.jsonl side effect).
 *
 * Usage:
 *   php generate-channel-surf.php            preview tc to stdout (+ verify)
 *   php generate-channel-surf.php --write    write .ansi + static files
 *   php generate-channel-surf.php --anim     also play the tc animation
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'channel-surf';
const TEXT = 'Phlix';

const W = 72;              // total columns (bezel + picture)
const H = 12;              // total rows
const IW = 70;             // picture width  (cols 1..IW)
const IH = 10;             // picture height (rows 1..IH)
const WX0 = 6;             // wordmark first picture col (29*2 = 58 wide → centred in 70)
const WY0 = 1;             // wordmark first picture row (7 cell rows → picture rows 1..7)

/** 5x7 pixel font; 2x scaled (row+col) → 58 cols x 7 cell rows. */
const FONT = [
    'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
    'h' => ['#....', '#....', '#.##.', '##..#', '#...#', '#...#', '#...#'],
    'l' => ['.##..', '..#..', '..#..', '..#..', '..#..', '..#..', '.###.'],
    'i' => ['.###.', '.....', '..#..', '..#..', '..#..', '..#..', '..#..'],
    'x' => ['#...#', '#...#', '.#.#.', '..#..', '.#.#.', '#...#', '#...#'],
];

/** Letter ink: warm amber ramp left → right (Phlix brand heat). */
const AMBER = ['#FF7A1A', '#FFB33A', '#FFD866'];
const SCAN = 0.80;                       // bottom half of every cell row (CRT scanline)
const SCREEN_BG = '#161018';             // locked picture background (warm near-black)
const HALO = 0.13;                       // bloom on cells adjacent to ink
const BEZEL = '#3A3F50';
const BEZEL_EDGE = '#262A38';
const OSD = '#CFD6E4';
const OSD_BG = '#0C1018';

/** Muted-retro SMPTE-style bars (7 x 10 cols across the 70-wide picture). */
const BARS = ['#C9C9BE', '#B9A93F', '#4FA9B9', '#3E9A55', '#A94F9E', '#C24A38', '#3E5AA9'];
const BARS_INK = '#26261F';

const GRID_BG = '#0E1526';
const GRID_LINE = '#2E4A7C';
const GRID_INK = '#6FA0D8';

const TEL_BG = '#141C6E';
const TEL_COLS = ['#E8E4D8', '#7FCFCF', '#E0C85A', '#D87F7F', '#8FCE8F'];

/** Static tints per hop (TV snow leaning toward the channel's dominant hue). */
const TINTS = ['neutral' => '#B9B9B9', 'warm' => '#C9A87E', 'cool' => '#8FB4C9', 'green' => '#9CC98F'];

const DESCRIPTION = 'Channel Surf: Phlix on a cathode-ray set — seeded TV static, muted SMPTE bars / crosshatch test card / teletext hops, RGB-ghosted emergence, a rolling-horizon interference band, locking on a clean warm-amber wordmark with CH-01 bug and signal bars.';

// ================================================================ MASK

/** @return array{0:list<list<bool>>,1:int,2:int} stroke[14][58], pw, ph */
function surfMask(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $rows = [];
    foreach (str_split(TEXT) as $ci => $ch) {
        $g = FONT[$ch];
        foreach ($g as $y => $line) {
            $rows[$y] = ($rows[$y] ?? '') . $line . ($ci < 4 ? '.' : '');
        }
    }
    $stroke = [];
    foreach ($rows as $y => $line) {           // 2x vertical
        $wide = '';
        for ($x = 0; $x < strlen($line); $x++) {   // 2x horizontal
            $wide .= $line[$x] . $line[$x];
        }
        $stroke[2 * $y] = $wide;
        $stroke[2 * $y + 1] = $wide;
    }
    return $cache = [$stroke, 58, 14];
}

function surfOn(int $x, int $y): bool
{
    if ($y < 0 || $y > 13 || $x < 0 || $x > 57) {
        return false;
    }
    [$s] = surfMask();
    return $s[$y][$x] === '#';
}

// ================================================================ COLOURS

function amberAt(int $colInWord): array
{
    return lkGradient(AMBER, $colInWord / 57);
}

/** Dim warm background at picture (col,row) — vignette toward the corners. */
function bgAt(int $c, int $r): array
{
    $dx = ($c - (IW - 1) / 2) / (IW / 2);
    $dy = ($r - (IH - 1) / 2) / (IH / 2);
    $k = max(0.0, min(1.0, 1 - 0.42 * ($dx * $dx * 0.9 + $dy * $dy * 1.15)));
    return lkScale(lkHex(SCREEN_BG), 0.45 + 0.55 * $k);
}

// ================================================================ CELL HELPERS

function cell(string $g = ' ', ?array $fg = null, ?array $bg = null): array
{
    return [$g, $fg, $bg];
}

function blankPicture(): array
{
    $pic = [];
    for ($r = 0; $r < IH; $r++) {
        $row = [];
        for ($c = 0; $c < IW; $c++) {
            $row[] = cell(' ', null, bgAt($c, $r));
        }
        $pic[] = $row;
    }
    return $pic;
}

function scrambleText(int $seed, int $len = 5): string
{
    $pool = '▓▒░#@%8XZ&';
    $p = mb_str_split($pool);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $p[(int) (lkNoise($i, $seed, 4242) * count($p))];
    }
    return $out;
}

// ================================================================ PATTERN BASES

/** The locked wordmark, full amber with scanline banding + corner halo. */
function logoCells(): array
{
    $pic = blankPicture();
    for ($r = 0; $r < 7; $r++) {
        for ($cc = 0; $cc < 58; $cc++) {
            $py = 2 * $r;
            $onTop = surfOn($cc, $py);
            $onBot = surfOn($cc, $py + 1);
            if ($onTop || $onBot) {
                $base = amberAt($cc);
                $fgC = lkShade($base, 0.10 - 0.22 * $r / 6);
                $bgC = $onBot ? lkScale($fgC, SCAN) : $fgC;
                $pic[WY0 + $r][WX0 + $cc] = cell('▀', $fgC, $bgC);
            } else {
                $near = surfOn($cc - 1, $py) || surfOn($cc + 1, $py)
                    || surfOn($cc - 1, $py + 1) || surfOn($cc + 1, $py + 1);
                if ($near) {
                    $pic[WY0 + $r][WX0 + $cc][2] = lkMix(bgAt(WX0 + $cc, WY0 + $r), amberAt($cc), HALO);
                }
            }
        }
    }
    return $pic;
}

/** Ghost copies: red/teal offset outlines drawn before the main ink. */
function ghostLogo(array &$pic, int $dx, float $str): void
{
    [$s] = surfMask();
    for ($py = 0; $py < 14; $py++) {
        for ($px = 0; $px < 58; $px++) {
            if (!surfOn($px, $py)) {
                continue;
            }
            $c = $px + $dx;
            if ($c < 0 || $c >= IW) {
                continue;
            }
            $tint = $dx > 0 ? [200, 60, 60] : [60, 190, 220];
            $mix = lkMix(bgAt(WX0 + $c, WY0 + intdiv($py, 2)), $tint, $str);
            $pic[WY0 + intdiv($py, 2)][WX0 + $c] = cell('█', $mix, null);
        }
    }
}

function barsCells(): array
{
    $pic = blankPicture();
    for ($r = 0; $r < IH; $r++) {
        for ($c = 0; $c < IW; $c++) {
            $col = BARS[intdiv($c, 10) % 7];
            $k = $c % 10 === 0 ? 0.55 : ($c % 10 === 9 ? 0.7 : 1.0);
            $rgb = lkHex($col);
            if ($r >= 8) {
                $rgb = lkScale(lkHex(BARS_INK), $k);
            }
            $pic[$r][$c] = cell(' ', null, lkScale($rgb, $k));
        }
    }
    return $pic;
}

function gridCells(): array
{
    $pic = blankPicture();
    for ($r = 0; $r < IH; $r++) {
        for ($c = 0; $c < IW; $c++) {
            $line = ($c % 7 === 3) || ($r % 3 === 1);
            $pic[$r][$c] = $line
                ? cell($c % 7 === 3 && $r % 3 === 1 ? '╋' : ($c % 7 === 3 ? '┃' : '─'), lkHex(GRID_LINE), lkHex(GRID_BG))
                : cell(' ', null, lkHex(GRID_BG));
        }
    }
    lkText($pic, intdiv(IW, 2) - 2, intdiv(IH, 2), '▚▞', lkHex(GRID_INK), lkHex(GRID_BG));
    return $pic;
}

function telCells(int $seed): array
{
    $pic = blankPicture();
    for ($r = 0; $r < IH; $r++) {
        for ($c = 0; $c < IW; $c++) {
            $pic[$r][$c] = cell(' ', null, lkHex(TEL_BG));
        }
    }
    for ($c = 2; $c < IW - 2; $c++) {
        $pic[0][$c] = cell(' ', null, lkHex(TEL_COLS[0]));   // double-height header bar
    }
    for ($r = 1; $r < IH; $r++) {
        for ($c = 2; $c < IW - 2; $c++) {
            $n = lkNoise($c, $r * 3 + $seed, 777);
            if ($n < 0.34) {
                continue;
            }
            $g = $n < 0.6 ? '▀' : ($n < 0.82 ? '▄' : '█');
            $col = lkHex(TEL_COLS[(int) (lkNoise($r, $c, $seed) * count(TEL_COLS))]);
            $pic[$r][$c] = cell($g, $col, lkScale(lkHex(TEL_BG), 0.85));
        }
    }
    return $pic;
}

// ================================================================ NOISE / ROLL

function noiseCell(int $c, int $r, int $seed, string $tint): array
{
    $g = lkNoise($c * 3 + 5, $r * 7 + 2, $seed);
    $v = lkNoise($c, $r, $seed + 711);
    $b = (int) (26 + $v * 205);
    $tinted = lkMix(lkMix([$b, $b, $b], lkHex(TINTS[$tint]), 0.22), [255, 255, 255], $g > 0.93 ? 0.35 : 0);
    return match (true) {
        $g < 0.62 => cell(' ', null, $tinted),
        $g < 0.80 => cell('▒', $tinted, null),
        $g < 0.90 => cell('▓', lkScale($tinted, 0.7), lkScale($tinted, 0.25)),
        default   => cell('█', lkShade($tinted, 0.3), null),
    };
}

function applyNoise(array &$pic, float $p, int $seed, string $tint): void
{
    for ($r = 0; $r < IH; $r++) {
        for ($c = 0; $c < IW; $c++) {
            if (lkNoise($c * 13 + 7, $r * 29 + 11, $seed) < $p) {
                $pic[$r][$c] = noiseCell($c, $r, $seed + $r, $tint);
            }
        }
    }
}

/** Rolling-horizon band: shift + brighten rows near $band (picture rows, float). */
function rollBand(array &$pic, float $band, int $seed): void
{
    for ($r = 0; $r < IH; $r++) {
        $d = $r - $band;
        if (abs($d) <= 1.4) {
            $k = ($r % 2 === 0) ? 4 : -3;
            $row = $pic[$r];
            for ($c = 0; $c < IW; $c++) {
                $src = ($c + $k + IW) % IW;
                $nc = noiseCell($c, $r, $seed + $r * 31, 'neutral');
                if ($nc[0] === ' ') {
                    $nc = cell('▓', [245, 245, 240], null);   // bright tear line
                }
                $row[$c] = lkNoise($c, $r, $seed + 900) < 0.55 ? $nc : $row[$src];
            }
            $pic[$r] = $row;
        } elseif (abs($d) <= 2.6) {
            $k = 0.55 + 0.45 * (abs($d) - 1.4) / 1.2;
            for ($c = 0; $c < IW; $c++) {
                $cell = $pic[$r][$c];
                $pic[$r][$c] = [$cell[0],
                    $cell[1] === null ? null : lkScale($cell[1], $k),
                    $cell[2] === null ? null : lkScale($cell[2], $k)];
            }
        }
    }
}

// ================================================================ OSD (bug + bars)

function drawOsd(array &$pic, string $ch, int $lit, int $seed, bool $glitch): void
{
    $label = 'CH ' . ($glitch && lkNoise(3, $seed, 1) > 0.5 ? scrambleText($seed, 2) : $ch);
    $x = IW - 7;
    for ($r = 0; $r < 2; $r++) {
        for ($c = $x - 1; $c < IW; $c++) {
            $pic[$r][$c] = cell(' ', null, lkMix(bgAt($c, $r), lkHex(OSD_BG), 0.78));
        }
    }
    lkText($pic, $x, 0, $label, lkHex(OSD));
    $cols = ['#5F6577', '#8FCE8F', '#FFD866', '#FF9A3C'];
    for ($i = 0; $i < 4; $i++) {
        $flick = $i === $lit && lkNoise($i, $seed, 55) > 0.5 ? -1 : 0;
        $n = max(0, $lit + $flick);
        $litOne = $i < $n;
        $g = ['▁', '▃', '▅', '▇'][$i];
        $pic[IH - 1][$i + 1] = cell($g, lkHex($litOne ? $cols[$i] : '#3A3F50'), lkScale(lkHex(SCREEN_BG), 0.8));
    }
    lkText($pic, 8, IH - 1, 'PH-1', lkHex('#4A5164'));
}

// ================================================================ FULL FRAME

function baseFor(array $spec): array
{
    [$kind, $seed] = $spec;
    $pic = match ($kind) {
        'static' => blankPicture(),
        'bars'   => barsCells(),
        'grid'   => gridCells(),
        'tel'    => telCells($seed),
        default  => logoCells(),
    };
    $glitch = true;
    $lit = 1;
    $ch = scrambleText($seed, 2);
    switch ($kind) {
        case 'static':
            applyNoise($pic, 1.0, $seed, $spec[2] ?? 'neutral');
            break;
        case 'bars':
            applyNoise($pic, 0.16, $seed, 'warm');
            $glitch = false; $ch = '03'; $lit = 2;
            break;
        case 'grid':
            applyNoise($pic, 0.15, $seed, 'cool');
            $glitch = false; $ch = '11'; $lit = 2;
            break;
        case 'tel':
            applyNoise($pic, 0.16, $seed, 'green');
            $glitch = false; $ch = '27'; $lit = 2;
            break;
        case 'ghost':
            $gc = max(0.12, $spec[2] * 0.42);          // RGB tear fades as noise clears
            ghostLogo($pic, 2, $gc);
            ghostLogo($pic, -2, $gc);
            applyNoise($pic, $spec[2], $seed, 'neutral');
            $lit = $spec[2] < 0.3 ? 3 : 2;
            break;
        case 'roll':
            ghostLogo($pic, 2, 0.20);
            applyNoise($pic, 0.30, $seed, 'neutral');
            rollBand($pic, $spec[2], $seed);
            $ch = '--'; $lit = 3;
            break;
        case 'flicker':
            applyNoise($pic, 0.05, $seed, 'neutral');
            $pic[3] = array_merge(array_slice($pic[3], 2), array_slice($pic[3], 0, 2));   // one torn row
            $ch = '01'; $lit = 3; $glitch = false;
            break;
        case 'pulse':
            for ($r = 0; $r < IH; $r++) {
                for ($c = 0; $c < IW; $c++) {
                    $cell = $pic[$r][$c];
                    $pic[$r][$c] = [$cell[0],
                        $cell[1] === null ? null : lkShade($cell[1], 0.18),
                        $cell[2] === null ? null : lkShade($cell[2], 0.14)];
                }
            }
            $ch = '01'; $lit = 4; $glitch = false;
            break;
    }
    return [$pic, $ch, $lit, $seed, $glitch];
}

/** Picture grid → full W×H page (bezel frame around it). */
function compose(array $pic): array
{
    $cells = [];
    $top = '┌' . str_repeat('─', IW) . '┐';
    $bot = '└' . str_repeat('─', IW) . '┘';
    $bez = lkHex(BEZEL);
    $topRow = [];
    foreach (mb_str_split($top) as $g) {
        $topRow[] = cell($g, $bez, null);
    }
    $cells[] = $topRow;
    for ($r = 0; $r < IH; $r++) {
        $row = [cell('│', $bez, null)];
        foreach ($pic[$r] as $c) {
            $row[] = $c;
        }
        $row[] = cell('│', $bez, null);
        $cells[] = $row;
    }
    $cells[] = array_map(static fn (string $g): array => cell($g, $bez, null), mb_str_split($bot));
    return $cells;
}

// ================================================================ TIMELINE

function timeline(): array
{
    $f = [];
    $s = 1;
    for (; $s <= 3; $s++) {
        $f[] = ['static', $s, 'neutral'];          // cold snow on power-up
    }
    $f[] = ['bars', $s++, 'warm'];  $f[] = ['bars', $s++, 'warm'];
    $f[] = ['static', $s++, 'warm'];
    $f[] = ['grid', $s++];          $f[] = ['grid', $s++];
    $f[] = ['static', $s++, 'cool'];
    $f[] = ['tel', $s++];           $f[] = ['tel', $s++];
    $f[] = ['static', $s++, 'green'];
    $f[] = ['static', $s++, 'neutral'];            // a dead hop between cuts
    $f[] = ['ghost', $s++, 0.72];                  // the wordmark ghosts up
    $f[] = ['ghost', $s++, 0.66];
    $f[] = ['ghost', $s++, 0.55];
    foreach ([-2, 0, 2, 4, 6, 8] as $band) {       // rolling horizon sweep
        $f[] = ['roll', $s++, $band];
    }
    $f[] = ['ghost', $s++, 0.34];
    $f[] = ['ghost', $s++, 0.18];
    $f[] = ['ghost', $s++, 0.08];
    $f[] = ['flicker', $s];                        // one torn-row wink…
    $f[] = ['pulse', 0];                           // …then the sync flash
    return $f;
}

function staticFrame(): array
{
    $pic = logoCells();
    drawOsd($pic, '01', 4, 99, false);
    return $pic;
}

// ================================================================ BUILD

function buildAll(string $depth): array
{
    $pins = [];                                   // tc only (deliverable format law)
    $frames = [];
    foreach (timeline() as $spec) {
        [$pic, $ch, $lit, $seed, $glitch] = baseFor($spec);
        drawOsd($pic, $ch, $lit, $seed, $glitch);
        $frames[] = lkEncode(compose($pic), $depth, $pins);
    }
    $static = lkEncode(compose(staticFrame()), $depth, $pins);
    return [$frames, $static];
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$play = in_array('--anim', $argv, true);
$dir = __DIR__;

$frames = null;
$static = null;
foreach (['tc'] as $depth) {                     // primary deliverable = truecolor
    [$frames, $static] = buildAll($depth);
    [$w, $h] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth, $depth);
    echo $static, "\n";
    fwrite(STDERR, "[$depth] {$w}x{$h} ok — " . count($frames) . " anim frames + final\n");
    $anim = lkAnim($frames, $static);
    if ($write) {
        file_put_contents("$dir/logo-" . SLUG . "-$depth.ansi", $static);
        file_put_contents("$dir/logo-" . SLUG . "-anim-$depth.ansi", $anim);
        fwrite(STDERR, "  wrote logo-" . SLUG . "-$depth.ansi + logo-" . SLUG . "-anim-$depth.ansi\n");
    }
}

if ($play) {
    echo "\n";
    passthru('php ' . escapeshellarg(__DIR__ . '/logo-play.php') . ' ' . escapeshellarg("$dir/logo-" . SLUG . "-anim-tc.ansi") . ' 100');
}

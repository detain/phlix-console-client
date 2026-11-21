<?php

declare(strict_types=1);

/**
 * generate-amber-phosphor-boot — the wordmark "Phlix" burns onto an old
 * amber-phosphor CRT terminal.
 *
 * The animation tells the boot story in five phases (48 frames + static):
 *   A  power-on raster line  (T 0.00–0.20)  — a single hot scanline strikes
 *      across the dark tube, phosphor halo above and below.
 *   B  vertical expansion    (T 0.20–0.35)  — the line splits into a bright
 *      bar and opens to full screen height, brightness surging at the seams.
 *   C  phosphor snow         (T 0.30–0.46)  — unlit specks of amber static
 *      scatter over the warm-black screen, then collapse.
 *   D  letter burn-in        (T 0.42–0.86)  — each letter strikes in
 *      left-to-right, over-exposed near-white with a swollen bloom, flickering
 *      and wobbling ±1 px like a hunting beam, then cools to brand amber;
 *      a raster band sweeps down behind the reveal.
 *   E  settle                (T 0.85–1.00)  — glow contracts, a faint
 *      shimmer band exits the bottom edge; the static frame is clean.
 *
 * Craft notes (house idioms):
 *   - Every pixel of the screen carries an explicit colour; lkHalfBlock()
 *     turns the 16-px rows into 8 cell rows and the alternating
 *     bright/dark pixel rows become ▀ half-block shading = CRT scanlines
 *     THROUGH the letters, not just on the background.
 *   - Glow is a bg-ramp: per-letter distance fields feed an exp falloff
 *     mixed toward the bloom amber over the warm-black base.
 *   - Palette is the amber phosphor family only: #FFD25A→#FFB000→#FF8C00
 *     strokes, #FFE0A0 raster line, #FF9B00 bloom, warm near-blacks;
 *     bezel plate #0D0803 with engraved frame + '● P3 PHOSPHOR' / '60 Hz'.
 *   - A corner vignette darkens the screen edges like tube geometry.
 *
 * Usage:
 *   php generate-amber-phosphor-boot.php           preview static + verify
 *   php generate-amber-phosphor-boot.php --write   write logo-*.ansi files here
 *   php logo-play.php logo-amber-phosphor-boot-anim-tc.ansi 90
 *   php logo-preview.php logo-amber-phosphor-boot-tc.ansi logo-amber-phosphor-boot-tc.png
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'amber-phosphor-boot';
const TEXT = 'Phlix';

const IW = 56;            // screen columns (cells)
const SH = 8;             // screen rows (cells) → 16 px rows
const W  = IW + 2;        // + engraved frame
const H  = SH + 2;
const PH = SH * 2;        // px rows
const CX = 28;            // power-line centre x (px)
const CY = 7;             // power-line centre row (px)
const Y0 = 3;             // wordmark top px row (10-px font, 16-px screen)

/** Pixel font for "Phlix", 10 rows tall, widths 6/5/4/4/6 (+1 gaps = 29 px). */
const FONT = [
    'P' => ['#####.', '#....#', '#....#', '#....#', '#####.', '#.....', '#.....', '#.....', '#.....', '#.....'],
    'h' => ['#....', '#....', '####.', '#...#', '#...#', '#...#', '#...#', '#...#', '#...#', '#...#'],
    'l' => ['.##.', '..#.', '..#.', '..#.', '..#.', '..#.', '..#.', '..#.', '..#.', '.##.'],
    'i' => ['.##.', '.##.', '....', '..#.', '..#.', '..#.', '..#.', '..#.', '..#.', '.##.'],
    'x' => ['#....#', '#....#', '.#..#.', '.#..#.', '..##..', '..##..', '.#..#.', '.#..#.', '#....#', '#....#'],
];

/** Amber phosphor (P3) palette — the only hues in the whole design. */
const OFF_BG   = '#070402';   // unlit tube
const BASE_BG  = '#150C04';   // lit screen, warm black
const INK_TOP  = '#FFD25A';   // stroke ramp: lit top
const INK_MID  = '#FFB000';   // brand amber
const INK_BOT  = '#FF8C00';   // stroke ramp: base
const HOT_W    = '#FFF0C8';   // over-exposed burn-in white
const LINE_C   = '#FFE0A0';   // hot raster line
const GLOW_C   = '#FF9B00';   // bloom tint
const SNOW_C   = '#FF9A2A';   // phosphor snow flecks
const PLATE_C  = '#0D0803';   // bezel plate background
const EDGE_C   = '#4A3512';   // engraved frame
const LABEL_C  = '#6A4E20';   // engraved bezel label
const LED_ON   = '#FFB000';   // power LED
const LED_OFF  = '#241806';

const ANIM_FRAMES = 48;       // + static; logo-play at 90 ms → ~4.4 s
const PLAY_MS     = 90;

const DESCRIPTION = 'Amber phosphor CRT boot: a hot raster line strikes across the dark tube, expands to full screen, phosphor snow scatters and collapses, then each letter of "Phlix" burns in over-exposed and flickering (beam wobble included) before cooling to steady brand-amber glow with half-block scanlines, corner vignette and an engraved bezel with power LED.';
const TAGS = ['amber-phosphor', 'crt-boot', 'burn-in', 'raster-line', 'phosphor-snow', 'scanlines', 'half-block-shading', 'bg-ramp-glow', 'vignette', 'animated'];

// ================================================================ HELPERS

function amClamp01(float $v): float
{
    return max(0.0, min(1.0, $v));
}

/** Gaussian bump; mu < -20 means "no band" → 0 everywhere. */
function amGauss(float $x, float $mu, float $sigma): float
{
    if ($mu < -20) {
        return 0.0;
    }
    $d = ($x - $mu) / $sigma;
    return exp(-$d * $d / 2);
}

/** Tube geometry: corners sit darker than the centre. */
function amVignette(int $x, int $y): float
{
    $dv = ((($x - CX) / 30.0) ** 2) * 0.75 + ((($y - 7.5) / 9.0) ** 2) * 0.9;
    return 1 - min(0.42, 0.5 * $dv);
}

// ================================================================ WORDMARK FIELDS

/**
 * Precompute the two static fields the render needs:
 *   inkAt[x][y] = letter index (0..4) at that px, or -1
 *   dLet[k][y][x] = distance in px from (x,y) to letter k's ink
 *
 * @return array{0: array, 1: array}
 */
function amFields(): array
{
    [$mask, $ranges] = lkLayout(FONT, TEXT, letterGap: 1, wordGap: 3, margin: intdiv(IW - 29, 2));
    $letters = str_split(TEXT);

    $inkAt = [];
    for ($x = 0; $x < IW; $x++) {
        $inkAt[$x] = array_fill(0, PH, -1);
    }
    foreach ($ranges as $k => [$x0, $x1]) {
        $g = FONT[$letters[$k]];
        foreach ($g as $gy => $row) {
            for ($x = $x0; $x < $x1; $x++) {
                if ($row[$x - $x0] === '#') {
                    $inkAt[$x][$gy + Y0] = $k;
                }
            }
        }
    }

    $dLet = [];
    foreach ($ranges as $k => [$x0, $x1]) {
        $g = FONT[$letters[$k]];
        $pts = [];
        foreach ($g as $gy => $row) {
            for ($x = $x0; $x < $x1; $x++) {
                if ($row[$x - $x0] === '#') {
                    $pts[] = [$x, $gy + Y0];
                }
            }
        }
        for ($y = 0; $y < PH; $y++) {
            for ($x = 0; $x < IW; $x++) {
                $best = INF;
                foreach ($pts as [$px, $py]) {
                    $d = ($x - $px) * ($x - $px) + ($y - $py) * ($y - $py);
                    if ($d < $best) {
                        $best = $d;
                    }
                }
                $dLet[$k][$y][$x] = sqrt($best);
            }
        }
    }
    return [$inkAt, $dLet];
}

// ================================================================ ANIMATION STATE

/**
 * Per-letter reveal state at time T / frame index.
 * @return array{0: list<int>, 1: list<float>, 2: list<float>, 3: list<int>} [on, hot, flicker, wobble]
 */
function amLetterPhases(float $T, int $frame): array
{
    $on = $hot = $fl = $dx = [];
    foreach (range(0, 4) as $k) {
        $start = 0.42 + 0.05 * $k;
        if ($T < $start) {
            $on[$k] = 0;
            $hot[$k] = 0.0;
            $fl[$k] = 1.0;
            $dx[$k] = 0;
            continue;
        }
        $on[$k] = 1;
        $hot[$k] = max(0.0, 1 - ($T - $start) / 0.17);
        $fl[$k] = $hot[$k] > 0.25 ? 0.55 + 0.65 * lkNoise($k * 11 + 3, $frame, 17) : 1.0;
        $dx[$k] = $hot[$k] > 0.55 ? (int) round(2 * lkNoise($k * 7 + 5, $frame, 29) - 1) : 0;
    }
    return [$on, $hot, $fl, $dx];
}

// ================================================================ RENDER

/**
 * Screen pixel colour at (x, y) for the "no letters yet" layer:
 * power line → expanding bar → lit base with phosphor snow.
 */
function amScreenBase(int $x, int $y, float $T, float $lineT, float $expT, int $frame, float $snowDens): array
{
    if ($T < 0.36) {
        if ($T < 0.20) {                                   // phase A: raster line
            $e = 1.2 + 29 * $lineT ** 1.7;
            if (abs($x - CX) > $e) {
                return lkHex(OFF_BG);
            }
            if ($y === CY) {
                return lkHex(LINE_C);
            }
            if ($y === CY + 1) {
                return lkScale(lkHex(LINE_C), 0.35);
            }
            if ($y === CY - 1) {
                return lkScale(lkHex(LINE_C), 0.16);
            }
            return lkHex(OFF_BG);
        }
        $h = 0.7 + 7.5 * $expT ** 0.9;                     // phase B: vertical expand
        $dy = abs($y - CY);
        if ($dy > $h) {
            return lkHex(OFF_BG);
        }
        $edgeWin = 0.9 * amClamp01(1 - $expT / 0.87);      // leading edges cool as the tube opens
        if ($edgeWin > 0 && $h - $dy < $edgeWin) {
            return lkHex(LINE_C);
        }
        return lkScale(lkMix(lkHex(OFF_BG), lkHex(BASE_BG), $expT), 0.55 + 0.45 * $expT);
    }
    $col = lkHex(BASE_BG);                                 // lit screen (+ phase C snow)
    if ($snowDens > 0.01) {
        $n = lkNoise($x, $y, $frame * 7 + 1);
        if ($n < $snowDens) {
            $m = lkNoise($y, $x, $frame * 7 + 5);
            $col = lkMix(lkScale($col, 1.6), lkHex(SNOW_C), 0.2 + 0.6 * $m);
        }
    }
    return $col;
}

/**
 * One animation frame (or the static at T=1.0): screen cell rows only.
 * Pixels are coloured, then lkHalfBlock() pairs them into cells — the
 * odd-row darkening (scanlines) survives as ▀/█ half-block shading.
 */
function amScreenCells(array $fields, float $T, int $frame): array
{
    [$inkAt, $dLet] = $fields;
    [$on, $hot, $fl, $dx] = amLetterPhases($T, $frame);
    $maxHot  = max($hot);
    $lineT   = amClamp01($T / 0.20);
    $expT    = amClamp01(($T - 0.20) / 0.15);
    $snow    = 0.62 * amGauss($T, 0.365, 0.05);
    $surge   = 1 + 0.45 * amGauss($T, 0.30, 0.05);
    $bandA   = $T >= 0.42 && $T < 0.90 ? -3 + ($T - 0.42) / 0.44 * 24 : -99.0;
    $bandB   = $T >= 0.855 ? -3 + ($T - 0.855) / 0.15 * 26 : -99.0;
    $bloom   = 1 + 0.95 * $maxHot;
    $glyphH  = count(FONT['P']);

    $px = [];
    for ($y = 0; $y < PH; $y++) {
        $row = [];
        for ($x = 0; $x < IW; $x++) {
            $col  = amScreenBase($x, $y, $T, $lineT, $expT, $frame, $snow);
            $scan = ($y & 1) ? 0.74 : 1.0;
            $bump = 1 + 0.22 * amGauss($y, $bandA, 1.1) + 0.10 * amGauss($y, $bandB, 1.4);
            $vig  = amVignette($x, $y);

            $ink = -1;
            if ($T >= 0.42) {
                for ($k = 0; $k < 5; $k++) {
                    if (!$on[$k]) {
                        continue;
                    }
                    $mx = $x - $dx[$k];
                    if ($mx >= 0 && $mx < IW && $inkAt[$mx][$y] === $k) {
                        $ink = $k;
                        break;
                    }
                }
            }

            if ($ink >= 0) {                               // burning letter stroke
                $c = lkGradient([INK_TOP, INK_MID, INK_BOT], ($y - Y0) / ($glyphH - 1));
                $c = lkMix($c, lkHex(HOT_W), 0.85 * $hot[$ink]);
                $row[$x] = lkScale($c, max(0.0, min(1.7, $fl[$ink] * $scan * $bump * $vig)));
                continue;
            }

            $s = 0.0;                                      // amber bloom bg-ramp
            for ($k = 0; $k < 5; $k++) {
                if (!$on[$k]) {
                    continue;
                }
                $mx = max(0, min(IW - 1, $x - $dx[$k]));
                $sk = exp(-max(0.0, $dLet[$k][$y][$mx] - 0.8) / 2.3) * (0.6 + 0.9 * $hot[$k]);
                if ($sk > $s) {
                    $s = $sk;
                }
            }
            if ($s > 0) {
                $col = lkMix($col, lkHex(GLOW_C), min(0.60, $s * 0.50 * $bloom));
            }
            $row[$x] = lkScale($col, max(0.0, min(1.6, $scan * $bump * $vig * $surge)));
        }
        $px[] = $row;
    }
    return lkHalfBlock($px);
}

/** Frame the screen cell rows with the engraved bezel, LED and labels. */
function amFrameLogo(array $screenRows, float $T): array
{
    $plate = lkHex(PLATE_C);
    $edge  = lkHex(EDGE_C);

    $top = array_fill(0, W, ['━', $edge, $plate]);
    $top[0] = ['┏', $edge, $plate];
    $top[W - 1] = ['┓', $edge, $plate];

    $bot = array_fill(0, W, ['━', $edge, $plate]);
    $bot[0] = ['┗', $edge, $plate];
    $bot[W - 1] = ['┛', $edge, $plate];

    $cells = [$top];
    foreach ($screenRows as $row) {
        $cells[] = array_merge([['┃', $edge, $plate]], $row, [['┃', $edge, $plate]]);
    }
    $cells[] = $bot;

    $led = amClamp01(($T - 0.03) / 0.12);
    $cells[H - 1][2] = ['●', lkMix(lkHex(LED_OFF), lkHex(LED_ON), $led), $plate];
    $label = $led > 0.6 ? lkHex(LABEL_C) : $plate;         // engraving wakes with power
    lkText($cells, 4, H - 1, 'P3 PHOSPHOR', $label, $plate);
    lkText($cells, W - 8, H - 1, '60 Hz', $label, $plate);
    return $cells;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$fields = amFields();

$staticT = 1.0;
$static = lkEncode(amFrameLogo(amScreenCells($fields, $staticT, -1), $staticT), 'tc');
[$w, $h] = lkVerify($static, 'static/tc');
lkCheckDepth($static, 'tc', 'static/tc');
if ($w > 80 || $h > 14) {
    exit("static {$w}x{$h} exceeds the 80x14 budget\n");
}
echo $static;
fwrite(STDERR, "[tc] static {$w}x{$h} ok\n");

if (!$write) {
    exit(0);
}

file_put_contents(__DIR__ . '/logo-' . SLUG . '-tc.ansi', $static);

$frames = [];
for ($f = 0; $f < ANIM_FRAMES; $f++) {
    $T = $f / ANIM_FRAMES;
    $frames[] = lkEncode(amFrameLogo(amScreenCells($fields, $T, $f), $T), 'tc');
}
$anim = lkAnim($frames, $static);
[$aw, $ah] = lkVerify(lkLastFrame($anim), 'anim/tc');
lkCheckDepth($anim, 'tc', 'anim/tc');
file_put_contents(__DIR__ . '/logo-' . SLUG . '-anim-tc.ansi', $anim);

$seconds = round((count($frames) + 1) * PLAY_MS / 1000, 2);
fwrite(STDERR, '  wrote logo-' . SLUG . '-tc.ansi (' . $w . 'x' . $h . ")\n");
fwrite(STDERR, '  wrote logo-' . SLUG . '-anim-tc.ansi (' . count($frames) . ' frames + static, ' . $aw . 'x' . $ah . ', play at ' . PLAY_MS . ' ms ≈ ' . $seconds . " s)\n");

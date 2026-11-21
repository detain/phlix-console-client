<?php

declare(strict_types=1);

/**
 * generate-golden-light-sweep — "Phlix" in dark brushed metal, a diagonal
 * light beam sweeps the wordmark and the metal gleams hot gold then cools
 * back; a lens flare (bright core + streak + ghost dots) rides just ahead of
 * the beam; the logo settles on a clean frame with a faint warm specular
 * highlight resting on the letter tops.
 *
 * Technique (house idiom — candy-top logo-kit):
 *   1. A small '#'/'.' pixel font is scaled 2x into a 16-sub-row mask so
 *      strokes come out an even 2 sub-px wide and vertical edges sit at
 *      half-cell resolution.
 *   2. Every ink pixel gets a metal base BEFORE the sweep: a lit top edge on
 *      each vertical ink run, gunmetal falloff to a dark base, and faint
 *      deterministic brushed-metal grain (lkNoise).
 *   3. The gleam is a proximity field around the moving diagonal beam line
 *      c = x + TILT*y, with asymmetric falloff — a narrow leading edge and a
 *      wide trailing side, so metal heats to warm-white then COOLS back
 *      (amber→gold→gunmetal) as the beam recedes.
 *   4. lkHalfBlock turns the pixel grid into ▀/▄/█ cells (the house ramp
 *      idiom); the lens flare and specular rests are then overlaid in cell
 *      space (lkPut) — fg-only glyphs on transparent bg.
 *   5. Frames: brief rest → beam+flare sweep → cool-down settle with the
 *      rest highlight rising → clean static final frame (lkAnim).
 *
 * Usage:
 *   php generate-golden-light-sweep.php            preview tc to stdout (+ verify)
 *   php generate-golden-light-sweep.php --write    write the .ansi deliverables
 *   php generate-golden-light-sweep.php --frame N  dump one encoded frame
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'golden-light-sweep';
const TEXT = 'Phlix';
const SCALE = 2;                     // 2x → strokes 2 sub-px wide

/** Base wordmark font (8 rows). '#' ink, '.' empty. Diagonals are
 *  edge-connected (2-col steps with a 1-col overlap) so they survive 2x. */
const FONT8 = [
    'P' => ['#####', '#...#', '#...#', '####.', '#....', '#....', '#....', '#....'],
    'h' => ['#.....', '#.....', '#.###.', '##...#', '#...#.', '#...#.', '#...#.', '#...#.'],
    'l' => ['.##.', '..#.', '..#.', '..#.', '..#.', '..#.', '..#.', '.###'],
    'i' => ['.#.', '.#.', '...', '.#.', '.#.', '.#.', '.#.', '.#.'],
    'x' => ['.......', '.......', '##...##', '.##.##.', '..###..', '.##.##.', '##...##', '##...##'],
];
const LETTER_GAP = 1;                // in base cols (×SCALE after scaling)
const TOP_PAD_SUB = 2;               // sub-rows of halo above the wordmark (room for the flare)

/** Gunmetal (the resting metal) + the warm gold ramp the gleam climbs. */
const GUN_DARK  = '#1B2027';
const GUN_MID   = '#454E5A';
const GUN_LIT   = '#82909E';
const WARM_TINT = '#99907C';         // faint warm cast on the lit top edge
const GOLD_RAMP = ['#5A3A12', '#B57A1E', '#E8B23C', '#F6D272', '#FFF2CC'];  // amber edge → hot core

/** Lens flare glyphs ride the beam; ghost dots trail it. */
const FLARE_CORE = '✺';
const FLARE_MID  = '◆';
const FLAR_DOT   = '·';
const FLAR_WARM  = '#FFF3D2';

/** Sweep geometry (sub-pixel space). */
const TILT     = 0.30;               // diagonal of the beam line
const HW_LEAD  = 3.2;                // narrow approach side
const HW_TRAIL = 9.5;                // wide cooling tail
const BEAM_IN  = 6;                  // sub-px the beam starts offscreen left
const BEAM_OUT = 6;                  // ...and ends offscreen right
const FLAR_AHEAD = 3.0;              // flare core rides ahead of the beam (sub-px)

/** Animation shape (play with logo-play.php, which self-caps under 5 s). */
const REST_FRAMES   = 4;             // at-rest metal before the beam arrives
const SWEEP_FRAMES  = 26;            // beam + flare cross the wordmark
const SETTLE_FRAMES = 6;             // cool-down with the rest highlight rising
// total = REST + SWEEP + SETTLE + 1 final static = 37 frames

// ================================================================ GEOMETRY

/** Scale the base font by SCALE in both axes. */
function scaledFont(): array
{
    $out = [];
    foreach (FONT8 as $ch => $rows) {
        $wide = array_map(
            static fn (string $r): string => preg_replace_callback('/./', static fn ($m) => $m[0] . $m[0], $r),
            $rows
        );
        $tall = [];
        foreach ($wide as $r) {
            $tall[] = $r;
            $tall[] = $r;
        }
        $out[$ch] = $tall;
    }
    return $out;
}

/** @return array{0: list<string>, 1: int, 2: int, 3: list<array{int,int}>} mask, subW, subH, letter x-ranges */
function wordmask(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    [$mask, $ranges] = lkLayout(scaledFont(), TEXT, letterGap: LETTER_GAP * SCALE, wordGap: 0, margin: 1);
    $subH = count($mask) + TOP_PAD_SUB;
    $mask = array_merge(array_fill(0, TOP_PAD_SUB, str_repeat('.', strlen($mask[0]))), $mask);
    return $cache = [$mask, strlen($mask[0]), $subH, $ranges];
}

/** For each column: first ink sub-row, and is-ink lookup. */
function inkInfo(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    [$mask, $subW, $subH] = wordmask();
    $top = array_fill(0, $subW, PHP_INT_MAX);
    $bot = array_fill(0, $subW, -1);
    $ink = [];
    for ($y = 0; $y < $subH; $y++) {
        for ($x = 0; $x < $subW; $x++) {
            $on = ($mask[$y][$x] ?? '.') === '#';
            $ink[$y][$x] = $on;
            if ($on) {
                $top[$x] = min($top[$x], $y);
                $bot[$x] = max($bot[$x], $y);
            }
        }
    }
    return $cache = [$ink, $top, $bot, $subW, $subH];
}

// ================================================================ METAL BASE

/**
 * Resting gunmetal for an ink pixel: lit top edge on the vertical run,
 * falloff to a dark base, faint brushed grain, a soft horizontal sheen that
 * is brightest mid-wordmark and dims toward the outer letters.
 */
function metalBase(int $x, int $y, int $subW): array
{
    [$ink, $top, $bot] = inkInfo();
    $run = $top[$x];
    $end = $bot[$x];
    $len = max(1, $end - $run);
    $ty = ($y - $run) / $len;                       // 0 lit top → 1 dark base
    $b = match (true) {
        $y === $run => 1.00,
        $ty < 0.12 => 0.88,
        $ty < 0.35 => lkLerp(0.84, 0.70, ($ty - 0.12) / 0.23),
        $ty < 0.72 => lkLerp(0.70, 0.60, ($ty - 0.35) / 0.37),
        default => lkLerp(0.60, 0.55, ($ty - 0.72) / 0.28),
    };
    $col = lkMix(lkHex(GUN_DARK), lkHex(GUN_LIT), $b);
    if ($b > 0.8) {
        $col = lkMix($col, lkHex(WARM_TINT), 0.35 * ($b - 0.8) / 0.2);   // warm on the top edge only
    }
    // brushed grain: horizontal streaks, slightly stronger mid-run, never blows out
    $grain = (lkNoise($x >> 1, $y, 7) - 0.5) * (0.10 + 0.06 * $b);
    $col = array_map(
        static fn (int $v, float $k): int => max(0, min(255, (int) round($v * (1 + $k)))),
        $col,
        [$grain, $grain * 1.25, $grain],
    );
    // mid-wordmark sheen
    $cx = ($x - $subW / 2) / ($subW / 2);
    $col = lkScale($col, 1 - 0.16 * $cx * $cx);
    return $col;
}

function lkLerp(float $a, float $b, float $t): float
{
    $t = max(0.0, min(1.0, $t));
    return $a + ($b - $a) * $t;
}

// ================================================================ GLEAM FIELD

/**
 * How hot an ink pixel is for beam position $bx: 0 = rest metal,
 * 1 = warm-white core. Asymmetric so the beam reads as "heats then cools".
 */
function gleam(float $x, float $y, float $bx): float
{
    $c = $x + TILT * $y;
    $u = $c - $bx;
    if ($u < 0) {                                    // leading / approaching — narrow
        $d = -$u / HW_LEAD;
    } else {                                          // trailing / cooling — wide
        $d = $u / HW_TRAIL;
    }
    if ($d >= 1.0) {
        return 0.0;
    }
    $t = 1.0 - $d;
    $t = $t ** (0.5 + 1.3 * $d);                     // sharp core, long soft tail
    return min(1.0, $t);
}

/** Blend metal → gold ramp by heat (ramp indexed on heat). */
function heatMetal(array $base, float $g): array
{
    if ($g <= 0.001) {
        return $base;
    }
    $hot = lkGradient(GOLD_RAMP, $g);
    $t = $g ** 1.15;
    return lkMix($base, $hot, $t);
}

// ================================================================ PIXEL GRID

/**
 * Full pixel grid for a frame (rest sub-rows are null). $restHi = amount of
 * the final resting top-highlight that has faded in (0..1).
 * @return list<list<?array>>
 */
function pixels(float $bx, float $restHi = 0.0): array
{
    [$ink, $top, $bot, $subW, $subH] = inkInfo();
    $px = array_fill(0, $subH, array_fill(0, $subW, null));
    for ($y = 0; $y < $subH; $y++) {
        for ($x = 0; $x < $subW; $x++) {
            if (!$ink[$y][$x]) {
                continue;
            }
            $col = metalBase($x, $y, $subW);
            $g = gleam((float) $x, (float) $y, $bx);
            if ($g > 0) {
                $col = heatMetal($col, $g);
            }
            // resting specular: warm-white 1-sub-row line on ink top runs
            if ($restHi > 0 && $y === $top[$x]) {
                $col = lkMix($col, [255, 244, 214], 0.85 * $restHi);
            }
            $px[$y][$x] = $col;
        }
    }
    return $px;
}

// ================================================================ FLARE LAYER

/**
 * Cell-space lens flare that rides ahead of the beam: a bright core on the
 * halo row + a short horizontal streak + ghost dots trailing the core, plus
 * a specular tick that skates along the tops of lit letters.
 */
function drawFlare(array &$cells, float $bx): void
{
    [$ink, $top, $bot, $subW, $subH] = inkInfo();
    $cellH = intdiv($subH, 2);
    $cellW = count($cells[0]);
    $coreX = intdiv((int) round($bx + FLAR_AHEAD), SCALE);
    // core rides on the halo band, brightest for the first half of the sweep
    if ($coreX >= 0 && $coreX < $cellW) {
        $lit = min(1.0, 1.05 - abs($coreX - $cellW / 2) / ($cellW / 2));
        lkPut($cells, $coreX, 0, FLARE_CORE, lkScale(lkHex(FLAR_WARM), 0.55 + 0.45 * $lit), null);
        // streak + ghosts along the tilt, fading below
        $g0 = max(0, $coreX - 1);
        $g1 = min($cellW - 1, $coreX + 1);
        if ($g1 > $g0) {
            lkPut($cells, $g0, 1, '─', lkScale(lkHex(FLAR_WARM), 0.5), null);
            lkPut($cells, $g1, 1, '─', lkScale(lkHex(FLAR_WARM), 0.5), null);
        }
        lkPut($cells, max(0, $coreX - 3), 1, FLAR_DOT, lkScale(lkHex(FLAR_WARM), 0.35), null);
        lkPut($cells, min($cellW - 1, $coreX + 3), 2, FLAR_WARM_DOT(), lkHex(FLAR_WARM), null);
    }
    // ghost dots down the beam path, fading with distance from the core
    for ($gy = 1; $gy < $cellH; $gy++) {
        $gx = $coreX - intdiv($gy * 3, 2);
        if ($gx < 0 || $gx >= $cellW) {
            continue;
        }
        $dim = max(0.18, 0.8 - 0.22 * $gy);
        $c = lkScale(lkHex(FLAR_WARM), $dim);
        lkPut($cells, $gx, $gy, $gy === 1 ? FLARE_MID : FLAR_DOT, $c, null);
    }
}

function FLAR_WARM_DOT(): string
{
    return '•';
}

// ================================================================ CELLS

/**
 * Complete cell grid for a frame.
 * @return list<list<array>>
 */
function cells(float $bx, float $restHi = 0.0): array
{
    $cells = lkHalfBlock(pixels($bx, $restHi));
    drawFlare($cells, $bx);
    return $cells;
}

// ================================================================ FRAMES

/**
 * Timeline of beam positions + rest-highlight levels.
 * @return list<array{0: float, 1: float}> [bx, restHi]
 */
function frames(): array
{
    [, $subW] = wordmask();
    $x0 = -BEAM_IN;
    $x1 = $subW - 1 + BEAM_OUT;
    $out = [];
    for ($i = 0; $i < REST_FRAMES; $i++) {
        $out[] = [$x0, 0.0];                        // at rest, flare parked offscreen left
    }
    for ($i = 0; $i < SWEEP_FRAMES; $i++) {
        $bx = $x0 + ($x1 - $x0) * $i / (SWEEP_FRAMES - 1);
        $out[] = [$bx, 0.0];
    }
    for ($i = 0; $i < SETTLE_FRAMES; $i++) {
        $t = ($i + 1) / (SETTLE_FRAMES + 1);
        $out[] = [$x1, 0.55 * $t];                   // cool-down; rest highlight rises
    }
    return $out;
}

// ================================================================ BUILD / CLI

/** Final static frame: clean wordmark with the resting highlight (no flare). */
function buildStatic(): string
{
    [, $subW] = wordmask();
    $cells = cells((float) ($subW + 99), 1.0);       // beam gone; highlight at rest
    return lkEncode($cells, 'tc');
}

$write = in_array('--write', $argv, true);
$onlyFrame = null;
foreach ($argv as $i => $a) {
    if ($a === '--frame' && isset($argv[$i + 1])) {
        $onlyFrame = (int) $argv[$i + 1];
    }
}

$static = buildStatic();
$seq = frames();
$animFrames = [];
foreach ($seq as [$bx, $rest]) {
    $animFrames[] = lkEncode(cells($bx, $rest), 'tc');
}
$anim = lkAnim($animFrames, $static);

if ($onlyFrame !== null) {
    echo $animFrames[$onlyFrame] ?? $static;
    exit;
}

// preview + verify on stdout/stderr
echo $static;
[$w, $h] = lkVerify($static, 'tc');
fwrite(STDERR, "[tc static] {$w}x{$h} ok\n");
foreach ($seq as $i => [$bx, $r]) {
    lkVerify($animFrames[$i], "frame $i");
}
lkVerify(lkLastFrame($anim), 'tc last-frame');
lkCheckDepth($anim, 'tc', 'tc anim');
$n = count($animFrames) + 1;
fwrite(STDERR, "[tc anim] {$n} frames, {$w}x{$h}, ok\n");

$dir = __DIR__;
if ($write) {
    file_put_contents("$dir/logo-golden-light-sweep-tc.ansi", $static);
    file_put_contents("$dir/logo-golden-light-sweep-anim-tc.ansi", $anim);
    $bytes = strlen($anim);
    fwrite(STDERR, "  wrote logo-golden-light-sweep-tc.ansi (" . strlen($static) . "B)\n");
    fwrite(STDERR, "  wrote logo-golden-light-sweep-anim-tc.ansi ({$bytes}B)\n");
    fwrite(STDERR, "  dims={$w}x{$h} frames={$n}\n");
}

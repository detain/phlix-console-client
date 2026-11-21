<?php

declare(strict_types=1);

/**
 * generate-adaptive-bitrate — "Phlix" as an adaptive-bitrate stream.
 *
 * Concept #12 for the phlix-console-client logo wave: the wordmark STREAMS
 * IN like a video player ramping quality. One hi-res master rendering of
 * Phlix (TTF coverage → amber gradient half-block art) is computed once;
 * every frame is that SAME artwork resolving through the ABR ladder
 * 144p → 240p → 360p → 480p → 720p → 1080p → 4K, block-averaged and
 * quantized per stage (macroblock dither, codec-gray mottling with the
 * logo's own hues leaking through, macroblock grid seams, a decode scan
 * through the blocks). A crisp player-UI quality chip in the top-right
 * corner ticks the ladder up; the video layer stays blocky until the
 * badge says 4K, where everything snaps to the clean static frame.
 *
 * Usage:
 *   php generate-adaptive-bitrate.php            preview static (tc) + stats to stderr
 *   php generate-adaptive-bitrate.php --write    write the three deliverables
 *   php logo-play.php logo-adaptive-bitrate-anim-tc.ansi 100
 *   php logo-preview.php logo-adaptive-bitrate-tc.ansi logo-adaptive-bitrate-tc.png
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'adaptive-bitrate';
const TEXT = 'Phlix';

const PW = 78;                 // master pixel columns = terminal columns
const PH = 24;                 // master pixel rows (2 per cell row)
const CW = 78;
const CH = 12;

const FONT_PATH = '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed-Bold.ttf';
const RASTER_SIZE = 180.0;
const INK_W = 66;              // ink box on the master grid
const INK_H = 21;
const INK_X = 6;               // ink box top-left (centered: (78-66)/2)
const INK_Y = 1;               // (24-21)/2 = 1.5 → sits one row up for badge room

/** Phlix warm ladder: pale gold → amber → ember orange, no rainbow. */
const GRADIENT = ['#FFD47E', '#FFB347', '#FF8A2A', '#F2592B'];
const BADGE_AMBER = '#F5A623';   // quality chip fill
const BADGE_DIM = '#7A5218';     // chip while the rung is still decoding
const BADGE_TEXT = '#1A1006';
const FIELD = [22, 24, 31];      // codec-black macroblock field (slight blue)

/** The ABR ladder. b=block px, L=color levels, sat=hue leakage, dith=dither,
 *  boil=per-frame block mottle, field=render empty blocks as gray, hard=no AA. */
const STAGES = [
    ['label' => '144p',  'b' => 6, 'L' => 4,  'sat' => 0.30, 'dith' => 1.00, 'boil' => 0.34, 'field' => true,  'hard' => true],
    ['label' => '240p',  'b' => 4, 'L' => 5,  'sat' => 0.42, 'dith' => 0.80, 'boil' => 0.26, 'field' => true,  'hard' => true],
    ['label' => '360p',  'b' => 3, 'L' => 6,  'sat' => 0.56, 'dith' => 0.55, 'boil' => 0.18, 'field' => true,  'hard' => true],
    ['label' => '480p',  'b' => 2, 'L' => 9,  'sat' => 0.72, 'dith' => 0.30, 'boil' => 0.10, 'field' => true,  'hard' => true],
    ['label' => '720p',  'b' => 1, 'L' => 16, 'sat' => 0.88, 'dith' => 0.00, 'boil' => 0.00, 'field' => false, 'hard' => true],
    ['label' => '1080p', 'b' => 1, 'L' => 48, 'sat' => 1.00, 'dith' => 0.00, 'boil' => 0.00, 'field' => false, 'hard' => false],
    ['label' => '4K',    'b' => 1, 'L' => 256, 'sat' => 1.00, 'dith' => 0.00, 'boil' => 0.00, 'field' => false, 'hard' => false, 'sheen' => true],
];

/** Frame schedule: [stage index, decode progress 0..1, boil seed].
 *  Progress < 1 renders not-yet-decoded macroblocks with the previous rung. */
const SCHEDULE = [
    [-1, 0.0, 0],                       // stream start: pure gray macroblock mess
    [0, 0.30, 0], [0, 0.60, 0], [0, 0.90, 0], [0, 1.0, 1], [0, 1.0, 2], [0, 1.0, 3],
    [1, 0.45, 0], [1, 1.0, 1], [1, 1.0, 2],
    [2, 0.55, 0], [2, 1.0, 1], [2, 1.0, 2],
    [3, 0.70, 0], [3, 1.0, 1], [3, 1.0, 2],
    [4, 0.0, 0], [4, 1.0, 0],            // 720p snaps sharp
    [5, 0.0, 0], [5, 1.0, 0],            // 1080p brings the half-block AA back
    [6, 0.0, 0], [6, 1.0, 0],            // 4K adds sheen + specular top edge
];

// ================================================================ MASTER

/**
 * Rasterize TEXT, measure its ink box, resample to the master pixel grid and
 * colour it: horizontal amber gradient + vertical shade + top specular, and
 * the 4K-only diagonal sheen. @return list<list<array{0:array,1:float}>> [rgb, alpha]
 */
function buildMaster(bool $sheen = false): array
{
    static $raster = null;
    if ($raster === null) {
        $im = imagecreatetruecolor(1400, 520);
        imagefill($im, 0, 0, 0);
        imagettftext($im, RASTER_SIZE, 0, 60, 360, 0xFFFFFF, FONT_PATH, TEXT);
        $x0 = $y0 = 1e9;
        $x1 = $y1 = -1;
        for ($y = 0, $h = imagesy($im); $y < $h; $y++) {
            for ($x = 0, $w = imagesx($im); $x < $w; $x++) {
                if ((imagecolorat($im, $x, $y) & 0xFF) > 30) {
                    $x0 = min($x0, $x); $x1 = max($x1, $x);
                    $y0 = min($y0, $y); $y1 = max($y1, $y);
                }
            }
        }
        $raster = [$im, $x0, $y0, $x1 - $x0 + 1, $y1 - $y0 + 1];
    }
    [$im, $bx, $by, $bw, $bh] = $raster;

    $scaleX = $bw / INK_W;
    $scaleY = $bh / INK_H;
    $master = [];
    for ($y = 0; $y < PH; $y++) {
        $row = [];
        for ($x = 0; $x < PW; $x++) {
            // 4x4 box-filtered coverage of the big raster for one master pixel
            if ($x < INK_X || $x >= INK_X + INK_W || $y < INK_Y || $y >= INK_Y + INK_H) {
                $row[$x] = [null, 0.0];
                continue;
            }
            $gx = $bx + ($x - INK_X) * $scaleX;
            $gy = $by + ($y - INK_Y) * $scaleY;
            $sum = 0.0;
            for ($dy = 0; $dy < 4; $dy++) {
                for ($dx = 0; $dx < 4; $dx++) {
                    $sx = (int) ($gx + ($dx + 0.5) * $scaleX / 4);
                    $sy = (int) ($gy + ($dy + 0.5) * $scaleY / 4);
                    $sum += (imagecolorat($im, $sx, $sy) & 0xFF) / 255;
                }
            }
            $alpha = $sum / 16;
            if ($alpha <= 0.002) {
                $row[$x] = [null, 0.0];
                continue;
            }
            $t = ($x - INK_X) / (INK_W - 1);
            $c = lkGradient(GRADIENT, $t);
            $c = lkShade($c, 0.10 - 0.26 * ($y - INK_Y) / (INK_H - 1));  // lit crown, deep base
            $row[$x] = [$c, min(1.0, $alpha)];
        }
        $master[$y] = $row;
    }

    // top specular: first solid pixel of each column catches the light
    for ($x = INK_X; $x < INK_X + INK_W; $x++) {
        for ($y = INK_Y; $y < INK_Y + INK_H; $y++) {
            if ($master[$y][$x][1] >= 0.5) {
                $master[$y][$x][0] = lkMix($master[$y][$x][0], [255, 255, 255], 0.24);
                break;
            }
        }
    }
    if (!$sheen) {
        return $master;
    }
    // 4K-only diagonal gloss band across the wordmark
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < PW; $x++) {
            [$c, $a] = $master[$y][$x];
            if ($c === null) {
                continue;
            }
            $d = ($x - 1.7 * $y - 16) / 7.5;
            $band = exp(-$d * $d) * (0.65 + 0.35 * lkNoise($x, $y, 7));
            $master[$y][$x][0] = lkMix($c, [255, 244, 214], min(0.30, $band * 0.42));
        }
    }
    return $master;
}

// ================================================================ ABR STAGES

/** Bayer 2x2 offsets in [-.5,.5) for ordered dithering. */
function bayer(int $i, int $j): float
{
    return [[0, 2], [3, 1]][$j % 2][$i % 2] / 4 - 0.5;
}

/** Quantize one channel to L levels with an ordered-dither offset. */
function quantize(float $v, int $levels, float $dOff): float
{
    if ($levels >= 256) {
        return max(0.0, min(255.0, $v));
    }
    $step = 255 / ($levels - 1);
    return max(0.0, min(255.0, round(($v + $dOff * $step) / $step) * $step));
}

/**
 * Colour the whole pixel grid at one ladder rung.
 * @return list<list<?array>> rgb|null per pixel (final ints, lkHalfBlock-ready)
 */
function stageGrid(array $master, int $stage, int $seed): array
{
    $cfg = STAGES[$stage];
    [$b, $levels, $sat, $dith, $boil, $hasField, $hard] =
        [$cfg['b'], $cfg['L'], $cfg['sat'], $cfg['dith'], $cfg['boil'], $cfg['field'], $cfg['hard']];
    $grid = array_fill(0, PH, array_fill(0, PW, null));

    if ($b > 1) {
        for ($by = 0; $by * $b < PH; $by++) {
            for ($bx = 0; $bx * $b < PW; $bx++) {
                $x0 = $bx * $b; $y0 = $by * $b;
                $x1 = min(PW, $x0 + $b); $y1 = min(PH, $y0 + $b);
                $n = ($x1 - $x0) * ($y1 - $y0);
                $avgA = 0.0;
                $acc = [0.0, 0.0, 0.0];
                $inkAcc = [0.0, 0.0, 0.0];
                for ($y = $y0; $y < $y1; $y++) {
                    for ($x = $x0; $x < $x1; $x++) {
                        [$c, $a] = $master[$y][$x];
                        $avgA += $a;
                        if ($c === null) {
                            continue;
                        }
                        $acc[0] += $a * $c[0]; $acc[1] += $a * $c[1]; $acc[2] += $a * $c[2];
                        $inkAcc[0] += $c[0];   $inkAcc[1] += $c[1];   $inkAcc[2] += $c[2];
                    }
                }
                $avgA /= $n;
                $inkMean = $avgA > 0.001
                    ? array_map(static fn (float $v): float => min(255.0, $v / ($avgA * $n)), $acc)
                    : [0.0, 0.0, 0.0];
                $inkAmt = min(1.0, $avgA * 2.2);
                $grumble = 1 + (lkNoise($bx, $by, $seed * 31 + $stage) - 0.5) * $boil * 2;
                $fieldCol = array_map(static fn (float $v): float => min(255.0, $v * (1 + 3.2 * $avgA) * $grumble), FIELD);
                $col = [0.0, 0.0, 0.0];
                for ($ch = 0; $ch < 3; $ch++) {
                    $col[$ch] = $fieldCol[$ch] + ($inkMean[$ch] - $fieldCol[$ch]) * $sat * (0.22 + 0.78 * $inkAmt);
                }
                $bs = max(1, intdiv($b, 2));
                for ($y = $y0; $y < $y1; $y++) {
                    for ($x = $x0; $x < $x1; $x++) {
                        $dOff = $dith > 0 ? bayer(intdiv($x, $bs), intdiv($y, $bs)) * $dith : 0.0;
                        $c = array_map(static fn (float $v): int => (int) round(quantize($v, $levels, $dOff)), $col);
                        // macroblock seam: dim the block's top row and left column
                        if ($y === $y0 || $x === $x0) {
                            $c = array_map(static fn (int $v): int => (int) round($v * 0.78), $c);
                        }
                        $grid[$y][$x] = $c;
                    }
                }
            }
        }
        return $grid;
    }

    // b === 1: sharp stages
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < PW; $x++) {
            [$c, $a] = $master[$y][$x];
            if ($c === null || ($hard ? $a < 0.45 : $a < 0.14)) {
                continue;
            }
            $lum = 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
            $base = $hasField ? $c : lkMix($c, [$lum, $lum, $lum], 1 - $sat);
            if (!$hard) {
                $base = lkScale($base, 0.30 + 0.70 * $a);   // coverage-as-shade AA
            }
            $grid[$y][$x] = array_map(static fn (float $v): int => (int) round(quantize($v, $levels, 0)), $base);
        }
    }
    return $grid;
}

/** Stream-start floor: gray macroblock noise, no hue yet. */
function preGrid(int $seed): array
{
    $grid = array_fill(0, PH, array_fill(0, PW, null));
    $b = STAGES[0]['b'];
    for ($by = 0; $by * $b < PH; $by++) {
        for ($bx = 0; $bx * $b < PW; $bx++) {
            $g = 1 + (lkNoise($bx, $by, $seed) - 0.5) * 0.7;
            $c = array_map(static fn (int $v): int => (int) max(4, min(48, round($v * $g))), [20, 22, 28]);
            for ($y = $by * $b; $y < min(PH, ($by + 1) * $b); $y++) {
                for ($x = $bx * $b; $x < min(PW, ($bx + 1) * $b); $x++) {
                    $grid[$y][$x] = $c;
                }
            }
        }
    }
    return $grid;
}

// ================================================================ COMPOSE

/** Merge stage grids by macroblock decode scan (raster block order). */
function frameGrid(array $master, int $stage, float $progress, int $seed): array
{
    if ($stage < 0) {
        return preGrid($seed);
    }
    $cur = stageGrid($master, $stage, $seed);
    if ($progress >= 1.0) {
        return $cur;
    }
    $prev = $stage === 0 ? preGrid($seed) : stageGrid($master, $stage - 1, $seed);
    $b = STAGES[$stage]['b'];
    $nbx = intdiv(PW + $b - 1, $b);
    $nby = intdiv(PH + $b - 1, $b);
    $total = $nbx * $nby;
    $decoded = (int) round($progress * $total);
    $grid = [];
    for ($y = 0; $y < PH; $y++) {
        $row = [];
        for ($x = 0; $x < PW; $x++) {
            $i = intdiv($y, $b) * $nbx + intdiv($x, $b);
            $row[$x] = $i < $decoded ? $cur[$y][$x] : $prev[$y][$x];
        }
        $grid[$y] = $row;
    }
    return $grid;
}

/** Crisp player-UI quality chip, overlaid on every frame. */
function badge(array &$cells, string $label, bool $locked, bool $on): void
{
    $cx = PW - 12;                       // chip right-aligned, 8 cols wide
    $chip = $on ? ($locked ? lkHex(BADGE_AMBER) : lkHex(BADGE_DIM)) : [38, 33, 24];
    $txt = $on ? lkHex(BADGE_TEXT) : [96, 88, 74];
    $face = str_pad($on ? $label : '····', 6, ' ', STR_PAD_BOTH);
    lkPut($cells, $cx, 0, ' ', null, $chip);
    lkText($cells, $cx + 1, 0, $face, $txt, $chip);
    lkPut($cells, $cx + 7, 0, ' ', null, $chip);
}

/** Pixel grid → half-block cells → badge → done. */
function cellsFor(array $master, int $stage, float $progress, int $seed): array
{
    $grid = frameGrid($master, $stage, $progress, $seed);
    $cells = lkHalfBlock($grid);
    if ($stage < 0) {
        badge($cells, '144p', false, false);
        return $cells;
    }
    badge($cells, STAGES[$stage]['label'], $progress >= 1.0, true);
    return $cells;
}

/** The finished 4K artwork: full master, sheen + specular, sharp. */
function finalCells(): array
{
    $master = buildMaster(sheen: true);
    $px = [];
    for ($y = 0; $y < PH; $y++) {
        $row = [];
        for ($x = 0; $x < PW; $x++) {
            [$c, $a] = $master[$y][$x];
            $row[$x] = $c === null || $a < 0.14 ? null : lkScale($c, 0.30 + 0.70 * $a);
        }
        $px[$y] = $row;
    }
    $out = lkHalfBlock($px);
    badge($out, '4K', true, true);
    return $out;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dir = __DIR__;

$static = lkEncode(finalCells(), 'tc');
[$w, $h] = lkVerify($static, SLUG);
lkCheckDepth($static, 'tc', SLUG);

echo $static;
fwrite(STDERR, "[tc] static {$w}x{$h} ok\n");

$master = buildMaster();
$frames = [];
foreach (SCHEDULE as [$stage, $progress, $seed]) {
    $frames[] = lkEncode(cellsFor($master, $stage, $progress, $seed), 'tc');
}
$anim = lkAnim($frames, $static);
lkVerify(lkLastFrame($anim), SLUG . '/anim');
lkCheckDepth($anim, 'tc', SLUG . '/anim');
foreach ($frames as $i => $f) {
    lkVerify($f, "frame $i");
}
fwrite(STDERR, '[tc] anim ' . count($frames) . "+1 frames ok\n");

if ($write) {
    file_put_contents("$dir/logo-" . SLUG . "-tc.ansi", $static);
    file_put_contents("$dir/logo-" . SLUG . "-anim-tc.ansi", $anim);
    fwrite(STDERR, '  wrote logo-' . SLUG . "-tc.ansi (" . strlen($static) . "B)\n");
    fwrite(STDERR, '  wrote logo-' . SLUG . "-anim-tc.ansi (" . strlen($anim) . "B)\n");
}

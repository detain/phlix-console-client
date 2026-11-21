<?php

declare(strict_types=1);

/**
 * generate-equalizer-skyline — concept #6: Equalizer Skyline.
 *
 * A truecolor half-block equalizer rack bounces to a 4/4 beat (kick drives
 * the left bass bands, snare swells the mids, hats shimmer the right).
 * Then the bars drain left-to-right down to a resting skyline — and as each
 * column settles, the wordmark "Phlix" is carved out of the still-lit block
 * of meter bars as deep knockout holes, chased by a brief warm chisel flash.
 * The wings keep a gentle idle bob; the final frame is the settled skyline:
 * amber at the feet of the bars, cooling to cyan at the peaks, peak-hold
 * ticks, a rack rail with mounting tabs underneath.
 *
 * Usage:
 *   php generate-equalizer-skyline.php            preview static + verify (stdout)
 *   php generate-equalizer-skyline.php --dump     ASCII skeleton of the final frame
 *   php generate-equalizer-skyline.php --write    write the .ansi deliverables
 *
 * View:  php logo-play.php logo-equalizer-skyline-anim-tc.ansi 33
 */

require __DIR__ . '/logo-kit.php';

const SLUG = 'equalizer-skyline';

// ---------------------------------------------------------------- geometry
const W = 60;                 // pixel/terminal columns
const PH = 20;                // pixel rows -> 10 cell rows after half-blocking
const RACK_BOT = 17;          // lowest rack row (px)
const MAXH = 16;              // tallest bar (px) -> tops at abs row 2
const GROUND = 18;            // rack-rail row
const MOUNT = 19;             // mounting-tab row
const X0 = 13;                // wordmark left column
const X1 = 46;                // wordmark right column (inclusive)
const LETTER_Y0 = 3;          // abs px row of font row 0

// ---------------------------------------------------------------- palette
const RAMP = ['#FF9E2E', '#FF5D78', '#3AD2E8'];  // bar bottom -> top (amber -> cool)
const HOLE = '#0A0F1D';       // carved letter depth
const CHISEL = '#FFD9A0';     // carve flash
const CHISEL_DIM = '#8A5B33';
const TICK = '#F2EAFF';       // peak-hold
const CLIP = '#FFF3D9';       // meter clip flash
const RAIL = '#39415E';       // ground rail
const TAB = '#2A3150';        // mounting tabs

// ---------------------------------------------------------------- timeline
const BOUNCE_END = 40;        // frames of pure groove
const DRAIN_STAGGER = 0.4;    // frames between successive column drain starts
const DRAIN_DUR = 7.0;        // frames per column to settle
const FLASH_DUR = 2.0;        // chisel flash frames after a column lands
const IDLE_START = 72;        // wings bob here
const N_FRAMES = 80;          // animated frames (final static rides on top)

/** 14-row pixel font, 2px strokes. 'Phlix' */
const FONT = [
    'P' => ['#####.', '##...#', '##...#', '##...#', '##...#', '#####.', '##....', '##....', '##....', '##....', '##....', '##....', '##....', '##....'],
    'h' => ['##.....', '##.....', '##.....', '##.....', '##.....', '##.....', '##.....', '##.###.', '##..###', '##...##', '##...##', '##...##', '##...##', '##...##'],
    'l' => ['###', '##.', '##.', '##.', '##.', '##.', '##.', '##.', '##.', '##.', '##.', '##.', '##.', '##.'],
    'i' => ['.##.', '.##.', '....', '....', '....', '....', '.##.', '.##.', '.##.', '.##.', '.##.', '.##.', '.##.', '.##.'],
    'x' => ['##..##', '##..##', '.####.', '..##..', '..##..', '.####.', '##..##', '##..##'],
];
const WORD = 'Phlix';
/** Top font row per glyph (baseline-aligned: x sits at x-height). */
const ROW0 = ['P' => 0, 'h' => 0, 'l' => 0, 'i' => 0, 'x' => 6];

/** @return array{0:array<int,array<int,bool>>,1:array<int,array{int,int}>} holes + letter ranges */
function esMask(): array
{
    $holes = [];
    $ranges = [];
    $x = X0;
    $chars = str_split(WORD);
    foreach ($chars as $i => $ch) {
        $g = FONT[$ch] ?? throw new InvalidArgumentException("no glyph $ch");
        $w = strlen($g[0]);
        $ranges[$i] = [$x, $x + $w - 1];
        foreach ($g as $r => $row) {
            for ($c = 0; $c < $w; $c++) {
                if ($row[$c] === '#') {
                    $holes[ROW0[$ch] + $r + LETTER_Y0][$x + $c] = true;
                }
            }
        }
        $x += $w + ($i < count($chars) - 1 ? 2 : 0);
    }
    return [$holes, $ranges];
}

/** Resting skyline height per column: wordmark block full-height, wings taper. */
function esRest(): array
{
    $rest = [];
    for ($x = 0; $x < W; $x++) {
        if ($x >= X0 && $x <= X1) {
            $rest[$x] = MAXH;
            continue;
        }
        $d = $x < X0 ? $x : (W - 1 - $x);                 // 0..12 from edge
        $lean = $x < X0 ? 0 : -2;                          // right wing sits lower
        $base = 2 + (int) round(10 * pow(($d + 1) / 13, 0.85)) + $lean;
        $jit = (int) floor(lkNoise($x, 3, 9) * 2.4) - 1;
        $rest[$x] = max(2, min(14, $base + $jit));
    }
    return $rest;
}

/** Beat envelopes: kick every half bar, snare backbeat, hats every frame pair. */
function esBeat(float $t): array
{
    $kick = pow(0.55, fmod($t, 7.0));
    $sn = fmod($t - 10.0 + 14.0, 14.0);
    $snare = $sn < 7.0 ? pow(0.45, $sn) : 0.0;
    $hat = fmod($t, 2.0) < 1.0 ? 1.0 : 0.4;
    return [$kick, $snare, $hat];
}

/** Per-column band weights: bass left, mids centre, hats right. */
function esWeight(int $x): array
{
    $b = max(0.0, 1.0 - $x / 24.0);
    $m = exp(-pow(($x - 30) / 14.0, 2));
    $h = max(0.0, ($x - 34) / 24.0);
    return [min(1.0, $b + 0.15 * $m), $m, min(1.0, $h + 0.1 * $m)];
}

/** Bouncing bar height (1..MAXH) for column x at groove time t. */
function esBounce(int $x, float $t, array $rest): int
{
    [$k, $s, $h] = esBeat($t);
    [$wB, $wM, $wH] = esWeight($x);
    $wob = 0.10 * (lkNoise($x, intdiv((int) $t, 2), 5) - 0.5);
    $e = 0.17 + 0.80 * $k * $wB + 0.62 * $s * $wM + 0.52 * $h * $wH + $wob + 0.05 * $k;
    return max(1, min(MAXH, (int) round($e * MAXH)));
}

/** Bar colour at absolute row $a in a column of height $h (ramp + clip). */
function esBar(int $a, int $h, int $x): array
{
    $t = $h <= 1 ? 1.0 : (RACK_BOT - $a) / ($h - 1);
    $c = lkGradient(RAMP, $t);
    if ($a === RACK_BOT - $h + 1 && $h >= MAXH - 1) {
        $c = lkMix($c, lkHex(CLIP), 0.55);            // lit meter heads
    }
    return lkScale($c, 0.95 + 0.08 * lkNoise($x, 11, 2));
}

/**
 * One frame as a pixel grid (rows of rgb|null).
 * @param float|null $t  null = final static; $ph peak-hold state by ref
 */
function esFrame(?float $t, array $holes, array $rest, array &$ph): array
{
    $px = array_fill(0, PH, array_fill(0, W, null));
    $px[GROUND] = array_fill(0, W, lkHex(RAIL));
    for ($x = 3; $x < W; $x += 8) {
        $px[MOUNT][$x] = lkHex(TAB);
    }

    $tickCols = [4, 11, 16, 24, 33, 41, 49, 56];      // peak-hold columns in the final frame

    for ($x = 0; $x < W; $x++) {
        $word = $x >= X0 && $x <= X1;
        if ($t === null) {
            $H = $rest[$x];
            $carve = true;
            $flash = 0.0;
        } else {
            $hb = esBounce($x, $t, $rest);
            if ($t < BOUNCE_END) {
                $H = $hb;
                $carve = false;
            } else {
                $start = BOUNCE_END + $x * DRAIN_STAGGER;
                $p = max(0.0, min(1.0, ($t - $start) / DRAIN_DUR));
                $e = 1 - pow(1 - $p, 3);
                $H = (int) round($hb + ($rest[$x] - $hb) * $e);
                $carve = $p > 0.45;
                $landed = $start + DRAIN_DUR;
                $flash = ($t >= $landed && $t < $landed + FLASH_DUR) ? $t - $landed : 0.0;
                if ($t >= IDLE_START && !$word && $p >= 1.0) {
                    $H = max(1, $rest[$x] + (int) round(sin(($t - IDLE_START) / 2.1 + $x * 0.55) * 0.9));
                }
            }
            $ph[$x] = $H > $ph[$x] ? (float) $H : max((float) $H, $ph[$x] - 0.55);
        }

        $top = RACK_BOT - $H + 1;
        for ($a = $top; $a <= RACK_BOT; $a++) {
            if ($word && $carve && isset($holes[$a][$x])) {
                $px[$a][$x] = $flash > 0 ? lkMix(lkHex(CHISEL), lkHex(CHISEL_DIM), min(1.0, $flash)) : lkHex(HOLE);
            } else {
                $px[$a][$x] = esBar($a, $H, $x);
            }
        }

        // peak-hold marker
        if ($t !== null) {
            $mk = RACK_BOT - (int) round($ph[$x]);
            if ($mk >= 0 && $mk < $top && $ph[$x] > $H + 0.6) {
                $px[$mk][$x] = lkHex(TICK);
            }
        }
    }

    if ($t === null) {
        foreach ($tickCols as $x) {
            $px[1][$x] = lkHex(TICK);
        }
    }
    return $px;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dump = in_array('--dump', $argv, true);
[$holes, $ranges] = esMask();
if (in_array('--heights', $argv, true)) {
    $rest = esRest();
    foreach ($rest as $x => $hgt) {
        printf("%2d:%2d ", $x, $hgt);
    }
    echo "\n";
    exit(0);
}
if (in_array('--mask', $argv, true)) {
    for ($a = 0; $a < PH; $a++) {
        $s = '';
        for ($col = 0; $col < W; $col++) {
            $s .= isset($holes[$a][$col]) ? '#' : '.';
        }
        printf("%2d %s\n", $a, $s);
    }
    foreach ($ranges as $i => [$a, $b]) {
        fwrite(STDERR, sprintf("%s: %d..%d\n", WORD[$i], $a, $b));
    }
    exit(0);
}
$rest = esRest();
$ph = array_fill(0, W, 0.0);

$staticCells = lkHalfBlock(esFrame(null, $holes, $rest, $ph));
$static = lkEncode($staticCells, 'tc');
[$w, $h] = lkVerify($static, 'static');
lkCheckDepth($static, 'tc', 'static');

if ($dump) {
    foreach ($staticCells as $row) {
        foreach ($row as [$g, $fg, $bg]) {
            $dark = $bg !== null && ($bg[0] + $bg[1] + $bg[2]) < 90;
            $isHole = $g === '█' && $fg !== null && ($fg[0] + $fg[1] + $fg[2]) < 90;
            echo $isHole ? ' ' : ($g === ' ' && !$dark ? '.' : ($dark && $g === ' ' ? '_' : ($g === ' ' ? ' ' : '█')));
        }
        echo "\n";
    }
    fwrite(STDERR, "dump: {$w}x{$h}\n");
    exit(0);
}

echo $static, "\n";
fwrite(STDERR, "[tc] static {$w}x{$h} ok\n");

$ph = array_fill(0, W, 0.0);
$frames = [];
for ($f = 0; $f < N_FRAMES; $f++) {
    $frames[] = lkEncode(lkHalfBlock(esFrame((float) $f, $holes, $rest, $ph)), 'tc');
}
$anim = lkAnim($frames, $static);
[$aw, $ah] = lkVerify(lkLastFrame($anim), 'anim');
lkCheckDepth($anim, 'tc', 'anim');
$nSep = substr_count($anim, "\e[{$h}A\r");
fwrite(STDERR, sprintf("[tc] anim %dx%d, %d frames, %d separators ok\n", $aw, $ah, count($frames) + 1, $nSep));

if ($write) {
    file_put_contents(__DIR__ . '/logo-' . SLUG . '-tc.ansi', $static);
    file_put_contents(__DIR__ . '/logo-' . SLUG . '-anim-tc.ansi', $anim);
    $mb = round(strlen($anim) / 1024);
    fwrite(STDERR, "  wrote logo-" . SLUG . "-tc.ansi ({$w}x{$h})\n  wrote logo-" . SLUG . "-anim-tc.ansi (~{$mb}KB)\n");
}

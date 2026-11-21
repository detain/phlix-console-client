<?php

declare(strict_types=1);

/**
 * generate-media-glyph-rain — "Phlix" built by a rain of media glyphs.
 *
 * Concept #16: a deterministic drizzle of ♪ ♫ ▸ • ✚ ✦ symbols falls through
 * the canvas. Where a drop's column crosses an unfilled letter cell it
 * CATCHES and sticks — so each letter progressively fills with the symbols
 * that hit it, while missed glyphs trail off the bottom. When a letter's
 * last cell catches, the whole letter flashes bright. Then a settle wave
 * merges each mosaic letter into solid, premium gold; the logo rests in
 * full polish while a few last stragglers rain past it.
 *
 * Amber/gold/cool-grey only: the cascade reads as texture, not confetti.
 *
 * Usage:
 *   php generate-media-glyph-rain.php            preview tc to stdout (+verify)
 *   php generate-media-glyph-rain.php --write    write the .ansi deliverables
 *   php logo-play.php logo-media-glyph-rain-anim-tc.ansi 55
 *
 * All writes stay inside this directory (no logos.jsonl side effect).
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG  = 'media-glyph-rain';
const TEXT  = 'Phlix';

const W = 44;            // columns (<= 80)
const H = 14;            // rows (<= 14)
const BAND = 4;          // first row of the 7-row letter band
const MARGIN = 9;        // rain padding left/right of the wordmark

/** Mixed-case 7-row cell font ('#' = one terminal cell a glyph can catch on). */
const FONT = [
    'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
    'h' => ['.....', '.....', '#....', '#....', '#####', '#...#', '#...#'],
    'l' => ['....',  '....',  '.##.',  '..#.',  '..#.',  '..#.',  '.###'],
    'i' => ['...',   '...',   '.#.',   '...',   '.#.',   '.#.',   '.#.'],
    'x' => ['.....', '.....', '#...#', '.#.#.',  '..#..', '.#.#.', '#...#'],
];

/** Falling symbol pool (weighted toward the music/▶ family). */
const RAIN_GLYPHS = ['♪', '♫', '♪', '▸', '•', '▸', '♪', '♫', '✚', '•', '◦', '✦', '▪', '○', '×', '♫'];

/** Rain colours + per-slot weights: ~2/3 cool grey, gold accents. */
const RAIN_COLS = ['#6E7787', '#565E6B', '#8A93A3', '#4A505A', '#B78E42', '#DAB26B', '#8C6D2F'];
const RAIN_COL_WEIGHT = [0, 1, 2, 3, 0, 1, 3, 2, 4, 0, 5, 1, 2, 6, 0, 3];

const GOLD_STOPS = ['#FFE9A8', '#F4C55F', '#DE9C33', '#B96F1F'];   // wordmark, left → right
const CAP_GLOSS  = 0.42;        // top-row half-block highlight
const MOSAIC_DIM = 0.82;        // resting caught glyph brightness vs letter gradient
const MOSAIC_GREY = [128, 134, 148];
const SHADOW     = '#3A2B14';   // ground row under the wordmark
const GLINT      = [255, 250, 230];
const SPARKLE    = '#6B5A32';

const SEED = 16;
const V = 0.9;                // rows per frame, fall speed
const DRIP = 5.0;             // frames between successive drops per column
const RAIN_TONE = 0.88;       // global restraint: cascade as texture, not confetti
const CATCH_FLASH = 5;        // frames a caught glyph flares before settling to mosaic tone
const LETTER_FLASH = 7;       // frames a completed letter flashes
const MERGE_DUR = 7;          // frames per cell, mosaic → solid
const LETT_STAG = 2.5;        // settle-wave stagger between letters
const TAIL = 10;              // straggler frames after the last merge
const STATIC_STRAGGLERS = [[2, 1], [15, 2], [29, 1], [41, 3], [3, 12], [12, 13], [24, 12], [38, 13]];

// ================================================================ LAYOUT

/** @return array{0:list<string>,1:array<int,array{int,int}>} mask + letter ranges */
function grMask(): array
{
    return lkLayout(FONT, TEXT, letterGap: 1, wordGap: 0, margin: MARGIN);
}

/** Entry time of column x's first drop (above the canvas, deterministic). */
function grEntry(int $x): float
{
    return lkNoise($x, 7, SEED) * 6.5;
}

/** Frame at which drop #k of column x begins its descent (y starts at -2). */
function grDropStart(int $x, int $k): float
{
    return $k === 0 ? grEntry($x) : grEntry($x) + $k * DRIP + lkNoise($x, $k, SEED) * 1.2;
}

/**
 * Per-column catch model: band rows a drop sticks to (top-down) plus the
 * frame each of those cells gets caught. Column full → drops run through.
 * @return array{0:array<int,list<int>>,1:array<int,list<float>>}
 */
function grCatches(array $mask): array
{
    $mw = strlen($mask[0]);
    $rows = [];
    $frames = [];
    for ($x = 0; $x < $mw; $x++) {
        $rr = [];
        for ($y = 0; $y < count($mask); $y++) {
            if ($mask[$y][$x] === '#') {
                $rr[] = $y;
            }
        }
        $rows[$x] = $rr;
        $fr = [];
        foreach ($rr as $j => $bandRow) {
            $fr[$j] = grEntry($x) + (BAND + $bandRow + 2) / V + $j * DRIP;
        }
        $frames[$x] = $fr;
    }
    return [$rows, $frames];
}

/** Max catch frame over every letter cell → schedule anchor for the settle wave. */
function grPhaseB(array $catchFrames): float
{
    $max = 0.0;
    foreach ($catchFrames as $fr) {
        foreach ($fr as $f) {
            $max = max($max, $f);
        }
    }
    return ceil($max) + 2;
}

/** Frame at which letter $li finishes catching (its last cell). */
function grLetterDone(array $catchFrames, array $ranges, int $li): float
{
    [$a, $b] = $ranges[$li];
    $last = 0.0;
    for ($x = $a; $x < $b; $x++) {
        foreach ($catchFrames[$x] ?? [] as $f) {
            $last = max($last, $f);
        }
    }
    return $last;
}

/** Letter index owning mask column x. */
function grLetterOf(int $x, array $ranges): int
{
    foreach ($ranges as $i => [$a, $b]) {
        if ($x >= $a && $x < $b) {
            return $i;
        }
    }
    return -1;
}

// ================================================================ COLOURS

/** Gold gradient of the wordmark at column x, shaded by band row y (0..6). */
function grLetterColor(int $x, int $y, int $mw): array
{
    $t = max(0.0, min(1.0, ($x - MARGIN) / max(1, $mw - 1)));
    $c = lkGradient(GOLD_STOPS, $t);
    return lkShade($c, 0.20 - 0.36 * ($y / 6));
}

function grRainColor(int $x, int $k, int $row): array
{
    $slot = RAIN_COL_WEIGHT[(int) (lkNoise($x, $k, SEED + 5) * count(RAIN_COL_WEIGHT)) % count(RAIN_COL_WEIGHT)];
    return lkScale(lkHex(RAIN_COLS[$slot]), RAIN_TONE * (0.72 + 0.28 * ($row / H)));
}

function grRainGlyph(int $a, int $b): string
{
    return RAIN_GLYPHS[(int) (lkNoise($a, $b, SEED + 11) * count(RAIN_GLYPHS)) % count(RAIN_GLYPHS)];
}

// ================================================================ BUILD

/**
 * Cell grid for animation time $t (frames); $t = null → the final static.
 * @return list<list<array{string,array|null,array|null}>>
 */
function grCells(?float $t, array $mask, array $ranges, array $catchRows, array $catchFrames): array
{
    $cells = lkBlankCells(W, H);
    $mw = strlen($mask[0]);
    $nLetters = count($ranges);
    $phaseB = $t === null ? 0.0 : grPhaseB($catchFrames);
    $done = [];
    $mergeAt = [];
    for ($li = 0; $li < $nLetters; $li++) {
        $done[$li] = grLetterDone($catchFrames, $ranges, $li);
        $mergeAt[$li] = $t === null ? -99.0 : $phaseB + $li * LETT_STAG;
    }

    // ---- ground shadow row (in place once the wordmark starts forming)
    if ($t === null || $t >= grEntry(MARGIN) + 4) {
        [$a0] = $ranges[0];
        [, $b1] = $ranges[$nLetters - 1];
        for ($x = $a0; $x < $b1; $x++) {
            if ($mask[6][$x] === '#') {
                lkPut($cells, $x, BAND + 7, '▒', lkHex(SHADOW));
            }
        }
    }

    // ---- rain (drawn before the letters: the logo catches in front of it)
    if ($t !== null) {
        for ($x = 0; $x < W; $x++) {
            for ($k = 0; $k < 24; $k++) {
                $y = -2.0 + V * ($t - grDropStart($x, $k));
                if ($y < 0) {
                    continue;
                }
                $row = (int) round($y);
                if ($row >= H) {
                    continue;
                }
                if ($cells[$row][$x][0] === ' ') {
                    lkPut($cells, $x, $row, grRainGlyph($x, $k), grRainColor($x, $k, $row));
                }
                // faint trail one row above (only while the pixel is rising toward it)
                $tr = $row - 1;
                if ($tr >= 0 && $y - floor($y) < 0.55 && $cells[$tr][$x][0] === ' ') {
                    lkPut($cells, $x, $tr, '∙', lkScale(grRainColor($x, $k, $row), 0.5));
                }
            }
        }
    }

    // ---- letter cells: caught mosaic glyphs → settle wave → solid premium gold
    for ($x = 0; $x < $mw; $x++) {
        if ($catchRows[$x] === []) {
            continue;
        }
        $li = grLetterOf($x, $ranges);
        $n = 0;
        foreach ($catchFrames[$x] as $f) {
            if ($t === null || $t >= $f) {
                $n++;
            }
        }
        foreach ($catchRows[$x] as $j => $bandRow) {
            if ($j >= $n) {
                continue;
            }
            $cellColor = grLetterColor($x, $bandRow, $mw);
            $ms = $mergeAt[$li] + floor(lkNoise($x, $bandRow, SEED + 31) * 3);
            $q = $t === null ? 1.0 : ($t - $ms) / MERGE_DUR;

            if ($q >= 1.0) {                       // SOLID
                $isTop = $bandRow === 0 || $mask[$bandRow - 1][$x] !== '#';
                $g = $isTop ? '▀' : '█';
                $fg = $isTop ? lkShade($cellColor, CAP_GLOSS) : $cellColor;
                $bg = $isTop ? $cellColor : null;
                if ($t !== null && $q < 1.25) {    // merge flash bell
                    $fg = lkMix($fg, GLINT, sin(max(0.0, min(1.0, ($q - 0.2) / 1.05)) * M_PI) * 0.85);
                }
                lkPut($cells, $x, $bandRow + BAND, $g, $fg, $bg);
                continue;
            }

            $glyph = grGlyph2($x, $j);             // per-cell catch glyph
            $c = lkMix(lkScale($cellColor, MOSAIC_DIM), MOSAIC_GREY, 0.16);
            $age = $t - $catchFrames[$x][$j];
            if ($age >= 0 && $age < CATCH_FLASH) { // fresh-catch flare
                $c = lkMix($c, GLINT, 1 - $age / CATCH_FLASH);
            }
            if ($t >= $done[$li] && $t < $done[$li] + LETTER_FLASH) {   // letter completes → flash
                $c = lkMix($c, GLINT, 0.5 * (1 - ($t - $done[$li]) / LETTER_FLASH));
            }
            if ($q > 0) {                          // transitioning to solid
                if ($q > 0.5) {
                    $glyph = '▒';
                }
                $c = lkMix($c, GLINT, sin(min(1.0, $q) * M_PI) * 0.7);
            }
            lkPut($cells, $x, $bandRow + BAND, $glyph, $c);
        }
    }

    // ---- static-only dressing: frozen stragglers + side sparkles
    if ($t === null) {
        foreach (STATIC_STRAGGLERS as $i => [$sx, $sy]) {
            lkPut($cells, $sx, $sy, grRainGlyph($sx, $i + 40), lkScale(grRainColor($sx, $i + 40, $sy), 0.8));
        }
        lkPut($cells, MARGIN - 4, BAND + 3, '✦', lkHex(SPARKLE));
        [, $bx] = $ranges[$nLetters - 1];
        lkPut($cells, $bx + 3, BAND + 3, '✦', lkHex(SPARKLE));
    }

    return $cells;
}

/** Glyph a caught cell wears in mosaic state (different hash lane than the fall). */
function grGlyph2(int $x, int $j): string
{
    return RAIN_GLYPHS[(int) (lkNoise($x, $j, SEED + 17) * count(RAIN_GLYPHS)) % count(RAIN_GLYPHS)];
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);

[$mask, $ranges] = grMask();
[$catchRows, $catchFrames] = grCatches($mask);
$phaseB = grPhaseB($catchFrames);
$nFrames = (int) ceil($phaseB + (count($ranges) - 1) * LETT_STAG + MERGE_DUR + 3 + TAIL);

$static = lkEncode(grCells(null, $mask, $ranges, $catchRows, $catchFrames), 'tc');
[$w, $h] = lkVerify($static, 'tc');
lkCheckDepth($static, 'tc', 'tc');
echo $static, "\n";
fwrite(STDERR, "[tc] {$w}x{$h} static ok · phaseB=$phaseB · {$nFrames} anim frames (~" . round($nFrames * 0.055, 1) . "s @55ms)\n");

$frames = [];
for ($f = 0; $f < $nFrames; $f++) {
    $frames[] = lkEncode(grCells((float) $f, $mask, $ranges, $catchRows, $catchFrames), 'tc');
}
$anim = lkAnim($frames, $static);
lkVerify(lkLastFrame($anim), 'tc/anim-last');

if ($write) {
    $dir = __DIR__;
    file_put_contents("$dir/logo-" . SLUG . "-tc.ansi", $static);
    file_put_contents("$dir/logo-" . SLUG . "-anim-tc.ansi", $anim);
    fwrite(STDERR, "  wrote logo-" . SLUG . "-tc.ansi (" . strlen($static) . "B)\n");
    fwrite(STDERR, "  wrote logo-" . SLUG . "-anim-tc.ansi (" . strlen($anim) . "B)\n");
}

<?php

declare(strict_types=1);

/**
 * generate-marquee-lights — "Phlix" on a cinema marquee sign.
 *
 * Concept #13: neon letters stutter on while bulbs chase around the sign.
 *   - a border circuit of round marquee bulbs ('●', two states: warm-white
 *     lit head vs dark-amber unlit glass) runs a 4-step chase clockwise;
 *   - the warm amber neon wordmark "Phlix" sputters alight letter-by-letter
 *     (hand-tuned flicker sequence per letter: flash, dropout, rise, snap);
 *   - a cool cyan accent tube underlines the wordmark, drawing on left→right
 *     while the last letters settle;
 *   - everything steadies and the border keeps a slow idle chase.
 *
 * Technique (house idiom): one truecolor pixel grid (W × ROWS*2) painted per
 * frame — panel/band structure + distance-field neon glow (precomputed
 * two-nearest fields per pixel, brightness is the only per-frame input) —
 * then lkHalfBlock() → cells; bulbs are overlaid as '●' cells on band bg.
 * Neon strokes read as bright white-hot core → amber tube → exp halo ramp,
 * exactly the half-block shading convention of generate-neon-tube-sign.php.
 *
 *   php generate-marquee-lights.php            # static to stdout + verify
 *   php generate-marquee-lights.php --write    # write .ansi deliverables
 *   php logo-play.php logo-marquee-lights-anim-tc.ansi 70
 *   php logo-preview.php logo-marquee-lights-tc.ansi logo-marquee-lights-tc.png
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'marquee-lights';
const W = 64;                 // columns  (≤ 80)
const ROWS = 13;              // rows     (≤ 14)
const PH = ROWS * 2;          // pixel-grid height (half-block: 2 px per row)
const FRAMES = 55;            // animation frames before the final static hold
const STATIC_F = 60;          // virtual frame the static hold is rendered at

/** Structure colours. */
const CAP_TOP = '#221812';    // cap bar, lit bevel edge
const CAP_BOT = '#191210';    // cap bar, under-edge / base bar top
const EDGE = '#100B0C';       // outer sign edge columns / base foot
const BAND = '#17110F';       // bulb channel band
const PANEL_T = '#0A0708';    // sign panel, top
const PANEL_B = '#0E0A0C';    // sign panel, bottom

/** Warm neon palette. */
const CORE = '#FFF6E2';       // white-hot tube centre
const AMBER = '#FFB23E';      // tube colour / halo
const AMBER_EDGE = '#FF942A'; // tube outer edge
const GLASS = '#241A20';      // unlit tube glass (warm dark)
/** Cool accent tube. */
const C_CORE = '#E8FBFF';
const C_EDGE = '#5CD9FF';
const C_HALO = '#2E9CC4';
const C_GLASS = '#1A242B';
/** Bulbs. */
const BULB_OFF = '#3A2617';   // unlit bulb glass
const BULB_HOT = '#FFF0C8';   // chase-head filament
const BULB_BG = '#A05618';    // halo tint the lit bulb paints on its cell bg

/**
 * Neon skeleton font (16 local rows, baseline row 13). '#' = tube ink.
 * 'P' cap 1..13, 'h'/'l' ascenders 2..13, 'i' dot 3 + stem 7..13, 'x' 6..13.
 */
const FONT = [
    'P' => [
        '.....', '####.', '#...#', '#...#', '#...#', '####.', '#....',
        '#....', '#....', '#....', '#....', '#....', '#....', '.....',
        '.....', '.....',
    ],
    'h' => [
        '.....', '.....', '#....', '#....', '#....', '#....', '#....',
        '#.##.', '##..#', '#...#', '#...#', '#...#', '#...#', '#...#',
        '.....', '.....',
    ],
    'l' => [
        '.....', '.....', '#...', '#...', '#...', '#...', '#...', '#...',
        '#...', '#...', '#...', '#...', '#...', '##..', '.###', '.....',
    ],
    'i' => [
        '...', '...', '...', '.#.', '...', '...', '...', '.#.', '.#.',
        '.#.', '.#.', '.#.', '.#.', '.#.', '...', '...',
    ],
    'x' => [
        '.....', '.....', '.....', '.....', '.....', '.....', '#...#',
        '#...#', '.#.#.', '..#..', '.#.#.', '#...#', '#...#', '#...#',
        '.....', '.....',
    ],
];

/** Letter placement: [char, gap after]. Wordmark centred from XO. */
const WORD = ['P', 'h', 'l', 'i', 'x'];
const GAP = 4;
const XO = 13;               // first letter column (wordmark spans cols 13..50)
const YO = 6;                // canvas py = local row + YO (cap row 1→7, baseline 13→19)

/** Cool accent underline: cols 11..52 at py 21, endcaps rise 1 px at the tips. */
const ULL_X0 = 11;
const ULL_X1 = 52;
const ULL_Y = 21;

/** Hand-tuned warm-up flicker per letter (P h l i x); after the list → steady 1. */
const FLICKER = [
    [0, 0, .55, .12, 0, .72, .28, .9, .15, .55, .08, .85, 1, .92, 1, 1],
    [0, 0, .4, .08, .62, .18, .86, .35, .95, .7, 1, .88, 1, 1],
    [0, .62, .1, .3, 0, .78, .22, .92, .5, .88, 1, 1],
    [0, 0, .5, .05, .72, .2, .9, .42, 1, .78, 1, 1],
    [0, .28, 0, .6, .08, .88, .46, 1, .68, .95, 1, 1],
];
const LETTER_START = [5, 9, 13, 16, 19];   // frame each letter begins sputtering
const ACCENT_START = 24;                    // underline draw-on begins / 4 cols per frame
const ACCENT_RATE = 4;

/** Bulb chase: 4-step running light, LUT indexed by (ring index + phase) % 4. */
const CHASE = [1, .55, .18, .05];

// ================================================================ GEOMETRY

/**
 * @return array{ink: array<int, list<array{int,int>}>, xs: array<int, int>}
 *         per-letter ink pixel lists + each letter's origin column.
 */
function inkLetters(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $ink = [];
    $xs = [];
    $x = XO;
    foreach (WORD as $li => $ch) {
        $xs[$li] = $x;
        foreach (FONT[$ch] as $r => $row) {
            $cols = strlen($row);
            for ($c = 0; $c < $cols; $c++) {
                if ($row[$c] === '#') {
                    $ink[$li][] = [$x + $c, $r + YO];
                }
            }
        }
        $x += strlen(FONT[$ch][0]) + GAP;
    }
    return $memo = ['ink' => $ink, 'xs' => $xs];
}

/** Accent underline ink pixels (memoised). */
function inkAccent(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $pts = [];
    for ($x = ULL_X0; $x <= ULL_X1; $x++) {
        $pts[] = [$x, ULL_Y];
    }
    $pts[] = [ULL_X0, ULL_Y - 1];
    $pts[] = [ULL_X1, ULL_Y - 1];
    return $memo = $pts;
}

/** Ring bulb positions, clockwise from top-left: [row, col]. */
function ringBulbs(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $b = [];
    for ($c = 2; $c <= W - 4; $c += 2) {
        $b[] = [1, $c];
    }
    for ($r = 2; $r <= 10; $r += 2) {
        $b[] = [$r, W - 2];
    }
    for ($c = W - 4; $c >= 2; $c -= 2) {
        $b[] = [ROWS - 2, $c];
    }
    for ($r = 10; $r >= 2; $r -= 2) {
        $b[] = [$r, 1];
    }
    return $memo = $b;
}

// ================================================================ STATIC FIELDS

/**
 * Per-pixel two-nearest warm-letter distance field (geometry never changes).
 * @return array<string, array> ['d1'=>..,'l1'=>..,'d2'=>..,'l2'=>..] grids
 */
function warmField(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $all = [];
    foreach (inkLetters()['ink'] as $li => $pts) {
        foreach ($pts as [$x, $y]) {
            $all[] = [$x, $y, $li];
        }
    }
    $d1 = $l1 = $d2 = $l2 = [];
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < W; $x++) {
            $b1 = INF; $b1i = -1; $b2 = INF; $b2i = -1;
            foreach ($all as [$ix, $iy, $li]) {
                $dd = ($x - $ix) * ($x - $ix) + ($y - $iy) * ($y - $iy) * 0.5;
                if ($dd < $b1) {
                    $b2 = $b1; $b2i = $b1i; $b1 = $dd; $b1i = $li;
                } elseif ($dd < $b2) {
                    $b2 = $dd; $b2i = $li;
                }
            }
            $d1[$y][$x] = $b1 === INF ? 99 : sqrt($b1);
            $l1[$y][$x] = $b1i;
            $d2[$y][$x] = $b2 === INF ? 99 : sqrt($b2);
            $l2[$y][$x] = $b2i;
        }
    }
    return $memo = compact('d1', 'l1', 'd2', 'l2');
}

/** Per-pixel distance to each accent ink point (for the left→right draw-on). */
function accentField(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $pts = inkAccent();
    $f = [];
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < W; $x++) {
            $best = [99, -1];
            foreach ($pts as $i => [$ax, $ay]) {
                $dd = sqrt(($x - $ax) * ($x - $ax) + ($y - $ay) * ($y - $ay) * 0.5);
                if ($dd < $best[0]) {
                    $best = [$dd, $i];
                }
            }
            $f[$y][$x] = $best;
        }
    }
    return $memo = $f;
}

/** Static base colours: the sign structure as a pixel grid. */
function baseGrid(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $capTop = lkHex(CAP_TOP); $capBot = lkHex(CAP_BOT); $edge = lkHex(EDGE);
    $band = lkHex(BAND); $pT = lkHex(PANEL_T); $pB = lkHex(PANEL_B);
    $g = [];
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < W; $x++) {
            $g[$y][$x] = match (true) {
                $y <= 1 => $y === 0 ? $capTop : $capBot,
                $y >= 24 => $y === 24 ? $capBot : $edge,
                $x === 0 || $x === W - 1 => $edge,
                $x === 1 || $x === W - 2 => $band,
                $y <= 3 || $y >= 22 => $band,
                default => lkMix($pT, $pB, ($y - 4) / 18),
            };
        }
    }
    return $memo = $g;
}

// ================================================================ FRAME RENDER

function clamp01(float $v): float
{
    return max(0.0, min(1.0, $v));
}

/** Letter brightness at frame f (0 = unlit glass, 1 = steady neon). */
function brightness(int $li, int $f): float
{
    $t = $f - LETTER_START[$li];
    if ($t < 0) {
        return 0.0;
    }
    $seq = FLICKER[$li];
    return $t < count($seq) ? (float) $seq[$t] : 1.0;
}

/** Chase head of the accent underline (column) at frame f. */
function accentFront(int $f): int
{
    if ($f < ACCENT_START) {
        return ULL_X0 - 1;
    }
    return min(ULL_X1 + 1, ULL_X0 + ($f - ACCENT_START) * ACCENT_RATE);
}

/** Chase phase of the bulb ring at frame f: fast while waking, slow at idle. */
function bulbPhase(int $f): int
{
    return $f < 36 ? 2 * $f : 72 + ($f - 36);
}

/**
 * Paint neon glow onto one pixel given its nearest-ink data.
 * @param float $d distance to tube ink
 * @param float $b brightness 0..1
 * @param array $haloC halo colour
 * @param array $coreC tube centre colour
 * @param array $edgeC tube edge colour
 * @param array $glassC unlit glass colour
 */
function neon(array $c, float $d, float $b, array $haloC, array $coreC, array $edgeC, array $glassC): array
{
    if ($b > 0) {
        $e = max(0.0, $d - 0.75);
        $k = min(0.6, $b * 0.55 * exp(-$e * $e / 2.6));
        $c = lkMix($c, $haloC, $k);
    }
    if ($d <= 1.15) {
        $body = lkMix($coreC, $edgeC, clamp01(($d - 0.2) / 0.9));
        $c = lkMix($c, lkMix($glassC, $body, clamp01($b * 1.2)), 0.95);
    }
    return $c;
}

/** Full pixel image for one frame. */
function pixels(int $f): array
{
    $F = warmField();
    $A = accentField();
    $warm = [lkHex(AMBER), lkHex(CORE), lkHex(AMBER_EDGE), lkHex(GLASS)];
    $cool = [lkHex(C_HALO), lkHex(C_CORE), lkHex(C_EDGE), lkHex(C_GLASS)];
    $LIT = [$warm, $warm, $warm, $warm, $warm];   // colour set per warm letter
    $front = accentFront($f);
    $b = [];
    foreach (WORD as $li => $_) {
        $b[$li] = brightness($li, $f);
    }
    $img = baseGrid();
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < W; $x++) {
            $c = $img[$y][$x];
            $c = neon($c, $F['d1'][$y][$x], $b[$F['l1'][$y][$x]], ...$LIT[$F['l1'][$y][$x]]);
            if ($F['d2'][$y][$x] < 5 && $F['l2'][$y][$x] >= 0) {   // neighbour halo bleed
                $e2 = max(0.0, $F['d2'][$y][$x] - 0.75);
                $c = lkMix($c, AMBER_R(), min(0.4, $b[$F['l2'][$y][$x]] * 0.45 * exp(-$e2 * $e2 / 2.6)));
            }
            [$da, $ai] = $A[$y][$x];
            if ($ai >= 0) {
                $c = neon($c, $da, inkAccent()[$ai][0] <= $front ? 1.0 : 0.0, ...$cool);
            }
            $img[$y][$x] = $c;
        }
    }
    return $img;
}

function AMBER_R(): array
{
    static $a = null;
    return $a ??= lkHex(AMBER);
}

/** Cell grid for one frame: half-block image + bulb overlay. */
function cells(int $f): array
{
    $cells = lkHalfBlock(pixels($f));
    $band = lkHex(BAND);
    $off = lkHex(BULB_OFF);
    $hot = lkHex(BULB_HOT);
    $tint = lkHex(BULB_BG);
    $phase = bulbPhase($f);
    foreach (ringBulbs() as $i => [$r, $c]) {
        $k = CHASE[($i + $phase) % 4];
        $fg = lkMix($off, $hot, $k ** 1.5);
        $bg = lkMix($band, $tint, $k * 0.5);
        lkPut($cells, $c, $r, '●', $fg, $bg);
    }
    return $cells;
}

function render(int $f): string
{
    return lkEncode(cells($f), 'tc');
}

// ================================================================ OUTPUT

$write = in_array('--write', $argv, true);
$dir = __DIR__;

$static = render(STATIC_F);
[$w, $h] = lkVerify($static, 'static');
lkCheckDepth($static, 'tc', 'static');

if (!$write) {
    echo $static, "\n";
    fwrite(STDERR, "[tc] {$w}x{$h} static ok\n");
}

if ($write) {
    file_put_contents("$dir/logo-marquee-lights-tc.ansi", $static);
    $frames = [];
    for ($f = 0; $f < FRAMES; $f++) {
        $frames[] = render($f);
    }
    $anim = lkAnim($frames, $static);
    [$aw, $ah] = lkVerify(lkLastFrame($anim), 'anim');
    lkCheckDepth($anim, 'tc', 'anim');
    file_put_contents("$dir/logo-marquee-lights-anim-tc.ansi", $anim);
    $n = count($frames) + 1;
    fwrite(STDERR, "[tc] static {$w}x{$h} · anim {$aw}x{$ah}, {$n} screens, ~"
        . round(($n * min(85, intdiv(4800, $n))) / 1000, 1) . "s at logo-play default\n");
    fwrite(STDERR, "wrote logo-marquee-lights-tc.ansi logo-marquee-lights-anim-tc.ansi\n");
}

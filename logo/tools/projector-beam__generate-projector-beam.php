<?php

declare(strict_types=1);

/**
 * generate-projector-beam — the "Phlix" wordmark caught inside a film
 * projector's beam: a light cone full of drifting dust throws the logo onto a
 * cinema screen, with gate flicker and a hair of weave-jitter as the reel
 * starts, settling to a clean projected title card.
 *
 * Scene is modelled as one continuous light buffer (76 x 26 sub-pixels, one
 * value per terminal half-block pixel), then half-blocked with lkHalfBlock —
 * the house idiom. Layers per pixel:
 *   theater dark  →  screen panel (cold, fine weave, lit batten)
 *   →  beam cone (gaussian profile around the lens→title axis, throw falloff)
 *   →  projected image (mask + bloom halo, brightness graded across the throw)
 *   →  dust motes (seeded, drifting, twinkling; inside the cone only)
 *   →  projector silhouette (body, twin reels, hot lens + flare)
 *   →  film flicker / jitter / scratch pulses (time only), grain, vignette.
 *
 * Palette is deliberately cinematic: warm beam creams (255,238-246,200-214)
 * against cold theater near-blues (10-24,11-26,18-33). No rainbow.
 *
 * Usage:
 *   php generate-projector-beam.php           verify + print the static frame
 *   php generate-projector-beam.php --write   write both .ansi files into this dir
 *   php generate-projector-beam.php --anim    play the tc animation here
 *   php generate-projector-beam.php --frame=40  print one animation frame
 *
 *   php logo-play.php logo-projector-beam-anim-tc.ansi 60
 *   php logo-preview.php logo-projector-beam-tc.ansi logo-projector-beam-tc.png
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'projector-beam';
const TEXT = 'Phlix';

const COLS = 76;                    // <= 80
const ROWS = 13;                    // <= 14
const PW = COLS;                    // scene pixels: 1 col = 1 px
const PH = ROWS * 2;                //            2 px = 1 row

/** Cinema screen panel (pixel coords, inclusive). */
const SCR = ['x0' => 24, 'x1' => 74, 'y0' => 2, 'y1' => 23];

/** Projector lens aperture (pixel coords). */
const LENS = ['x' => 15.0, 'y' => 20.5];

/** Beam cone: half-angle (deg) and softness (gaussian sigma as a factor of it). */
const BEAM_DEG = 13.0;
const BEAM_SOFT = 1.45;
const BEAM_REF = 26.0;              // throw-falloff reference distance (px)

const FRAMES = 72;                  // ~4.3 s at 60 ms; ends on the static frame
const FRAME_MS = 60;
const SEED = 1102;

/** 13-px-tall mixed-case wordmark (cap P + ascenders, x-height letters sit low). */
const FONT = [
    'P' => [
        '####.', '#...#', '#...#', '#...#', '#...#', '####.', '#....',
        '#....', '#....', '#....', '#....', '#....', '#....',
    ],
    'h' => [
        '#....', '#....', '#....', '#....', '#....', '.####', '#...#',
        '#...#', '#...#', '#...#', '#...#', '#...#', '#...#',
    ],
    'l' => [
        '.#.', '.#.', '.#.', '.#.', '.#.', '.#.', '.#.',
        '.#.', '.#.', '.#.', '.#.', '.#.', '###',
    ],
    'i' => [
        '...', '.#.', '...', '...', '...', '.#.', '.#.',
        '.#.', '.#.', '.#.', '.#.', '.#.', '###',
    ],
    'x' => [
        '.....', '.....', '.....', '.....', '.....', '#...#', '#...#',
        '.#.#.', '..#..', '..#..', '.#.#.', '#...#', '#...#',
    ],
];
const LETTER_GAP = 2;

/** Restrained cinema palette — warm light, cold dark. */
const C_THEATER_TOP = '#0A0B12';
const C_THEATER_BOT = '#141421';
const C_FLOOR = '#191410';
const C_SCREEN = '#171A24';
const C_SCREEN_LO = '#12141C';
const C_BATTEN = '#494036';
const C_AIR = '#B98E4E';            // beam through haze (amber)
const C_POOL = '#D8A75E';           // beam landing on the screen surface
const C_LETTER = '#FFEDD6';         // projected title core
const C_HALO = '#C98F3F';           // bloom around the letters
const C_DUST = '#FFF4DC';
const C_LENS = '#FFFDF4';
const C_BODY = '#20222B';
const C_BODY_LIT = '#4A4237';
const C_REEL = '#191B23';
const C_REEL_LIT = '#3A3630';

const DESCRIPTION = 'Phlix projected by a film projector: a warm light cone full of drifting dust throws the wordmark onto a cinema screen, with gate flicker and weave jitter as the reel starts.';
const TAGS = ['projector', 'film-beam', 'light-cone', 'dust-motes', 'cinema-screen', 'film-flicker', 'animated'];

// ================================================================ WORDMARK

/**
 * @return array{0: list<string>, 1: int, 2: int} mask rows, width, height
 */
function pbMask(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    [$mask] = lkLayout(FONT, TEXT, letterGap: LETTER_GAP, wordGap: LETTER_GAP, margin: 0);
    return $memo = [$mask, strlen($mask[0]), count($mask)];
}

/** Where the wordmark sits on the screen (pixel coords). */
function pbPlace(): array
{
    [$m, $w, $h] = pbMask();
    return [
        SCR['x0'] + intdiv(SCR['x1'] - SCR['x0'] + 1 - $w, 2),   // x0
        intdiv(SCR['y0'] + SCR['y1'] - $h, 2),                   // y0
        $w, $h,
    ];
}

/**
 * Projected-image plate: letter core + bloom halo, one 0..1 buffer the size of
 * the whole scene (so per-frame jitter is a cheap sample offset).
 * @return list<list<float>>
 */
function pbPlate(): array
{
    static $plate = null;
    if ($plate !== null) {
        return $plate;
    }
    [$m, $w, $h] = pbMask();
    $ink = [];
    for ($y = 0; $y < $h; $y++) {
        $row = [];
        for ($x = 0; $x < $w; $x++) {
            $row[] = $m[$y][$x] === '#' ? 1.0 : 0.0;
        }
        $ink[] = $row;
    }
    $glow = pbBlur(pbBlur($ink, $h, $w, 2), $h, $w, 1);
    $plate = array_fill(0, PH, array_fill(0, PW, 0.0));
    [$px0, $py0] = pbPlace();
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $v = min(1.0, $ink[$y][$x] + $glow[$y][$x] * 0.62);
            if ($v > 0.004) {
                $plate[$py0 + $y][$px0 + $x] = $v;
            }
        }
    }
    return $plate;
}

/** Two-pass box blur on an h x w float grid. */
function pbBlur(array $m, int $h, int $w, int $r): array
{
    $k = 2 * $r + 1;
    $t = [];
    for ($y = 0; $y < $h; $y++) {
        $row = [];
        for ($x = 0; $x < $w; $x++) {
            $s = 0.0;
            for ($d = -$r; $d <= $r; $d++) {
                $s += $m[$y][max(0, min($w - 1, $x + $d))];
            }
            $row[] = $s / $k;
        }
        $t[$y] = $row;
    }
    $o = [];
    for ($y = 0; $y < $h; $y++) {
        $row = [];
        for ($x = 0; $x < $w; $x++) {
            $s = 0.0;
            for ($d = -$r; $d <= $r; $d++) {
                $s += $t[max(0, min($h - 1, $y + $d))][$x];
            }
            $row[] = $s / $k;
        }
        $o[$y] = $row;
    }
    return $o;
}

// ================================================================ DUST

/** @return list<array{u:float,v:float,spd:float,tw:float,ph:float,sz:float}> */
function pbMotes(): array
{
    static $motes = null;
    if ($motes !== null) {
        return $motes;
    }
    $motes = [];
    for ($i = 0; $i < 34; $i++) {
        $motes[] = [
            'u' => lkNoise($i, 1, SEED),
            'v' => (lkNoise($i, 2, SEED) * 2 - 1) * 0.82,
            'spd' => 0.0016 + lkNoise($i, 3, SEED) * 0.0046,
            'tw' => 1.1 + lkNoise($i, 4, SEED) * 3.4,
            'ph' => lkNoise($i, 5, SEED) * 6.283,
            'sz' => lkNoise($i, 6, SEED) > 0.72 ? 1.0 : 0.66,
        ];
    }
    return $motes;
}

// ================================================================ MOTION

/**
 * Time state for frame $t (null = settled static frame).
 * @return array{lamp:float,settle:float,flick:float,jx:int,jy:int,scratch:?int,breath:float}
 */
function pbMotion(?float $t): array
{
    if ($t === null) {
        return ['lamp' => 1.0, 'settle' => 0.0, 'flick' => 1.0, 'jx' => 0, 'jy' => 0, 'scratch' => null, 'breath' => 1.0];
    }
    $ignite = pbSmooth($t, 2.0, 8.5);
    // lamp strikes: two hard stutters before it holds
    $lamp = $ignite * ($t > 3 && $t < 4 ? 0.35 : ($t >= 5 && $t < 6 ? 0.62 : 1.0));
    $settle = exp(-max(0.0, $t - 17.0) / 15.0);                    // motion energy decays
    $breath = 1.0 + 0.012 * sin($t * 0.34 + 0.7);                  // slow lamp breath
    $flick = 1.0 + (0.115 * $settle + 0.016) * (lkNoise((int) $t, 11, SEED) - 0.48) * 2
        + 0.045 * $settle * sin($t * 2.6);
    if ($settle > 0.18 && ((int) $t % 11) === 4) {
        $flick *= 1 - 0.40 * $settle;                              // gate dropout
    }
    if ($settle > 0.18 && ((int) $t % 11) === 5) {
        $flick *= 1 - 0.16 * $settle;
    }
    $amp = $settle;
    $jx = (int) round($amp * 1.7 * sin($t * 1.83 + 0.4) + $amp * 0.6 * (lkNoise((int) $t, 21, SEED) - 0.5) * 2);
    $jy = (int) round($amp * 1.2 * sin($t * 1.31 + 2.1) + $amp * 0.5 * (lkNoise((int) $t, 22, SEED) - 0.5) * 2);
    $scratch = null;
    foreach ([25, 39, 53, 62] as $sf) {
        if ((int) $t === $sf) {
            $scratch = 30 + (int) floor(lkNoise($sf, 31, SEED) * 40);
        }
    }
    return ['lamp' => $lamp, 'settle' => $settle, 'flick' => max(0.25, $flick), 'jx' => $jx, 'jy' => $jy, 'scratch' => $scratch, 'breath' => $breath];
}

function pbSmooth(float $v, float $a, float $b): float
{
    $p = max(0.0, min(1.0, ($v - $a) / ($b - $a)));
    return $p * $p * (3 - 2 * $p);
}

// ================================================================ SCENE

const ADD = 1;       // helper: additive clamp
function pbAdd(array $c, array $k, float $g): array
{
    if ($g <= 0.0) {
        return $c;
    }
    return [
        min(255, (int) round($c[0] + $k[0] * $g)),
        min(255, (int) round($c[1] + $k[1] * $g)),
        min(255, (int) round($c[2] + $k[2] * $g)),
    ];
}

/**
 * Render the whole scene as a pixel grid (rows of [r,g,b]).
 * @return list<list<array>>
 */
function pbScene(?float $t): array
{
    $mo = pbMotion($t);
    $plate = pbPlate();
    [$px0, $py0, $pew, $peh] = pbPlace();
    $air = lkHex(C_AIR);
    $pool = lkHex(C_POOL);
    $letterC = lkHex(C_LETTER);
    $halo = lkHex(C_HALO);
    $dust = lkHex(C_DUST);
    $tan = tan(deg2rad(BEAM_DEG));
    // beam axis: lens -> centre of the projected title
    $ax = $px0 + $pew / 2 - LENS['x'];
    $ay = $py0 + $peh / 2 - LENS['y'];
    $len = hypot($ax, $ay);
    $dx = $ax / $len;
    $dy = $ay / $len;
    $seed = $t === null ? 0 : (int) $t;

    $px = [];
    for ($y = 0; $y < PH; $y++) {
        $row = [];
        $fy = $y / (PH - 1);
        for ($x = 0; $x < PW; $x++) {
            $inScreen = $x >= SCR['x0'] && $x <= SCR['x1'] && $y >= SCR['y0'] && $y <= SCR['y1'];
            // --- base
            if ($inScreen) {
                $weave = ((($x + $y) & 1) === 0) ? 1.0 : -1.0;
                $c = [
                    lkHex(C_SCREEN)[0] + (int) round($weave * 1.4),
                    lkHex(C_SCREEN)[1] + (int) round($weave * 0.9),
                    lkHex(C_SCREEN)[2] + (int) round($weave * 1.2),
                ];
                if ($y === SCR['y0']) {
                    $c = lkHex(C_SCREEN_LO);                    // top shadow under the batten
                }
            } else {
                $c = lkMix(lkHex(C_THEATER_TOP), lkHex(C_THEATER_BOT), $fy);
                $fx = ($x - 10) / 16.0;
                $fyy = ($y - 24.5) / 2.6;
                $fl = exp(-$fx * $fx - $fyy * $fyy);            // warm spill on the floor
                $c = pbAdd($c, lkHex(C_FLOOR), 0.9 * $fl);
            }
            // --- beam cone (haze in the air, pool on the screen)
            $rx = $x - LENS['x'];
            $ry = $y - LENS['y'];
            $s = $rx * $dx + $ry * $dy;
            $light = 0.0;
            if ($s > 0.4) {
                $u = -$rx * $dy + $ry * $dx;
                $hw = 1.5 + $s * $tan;
                $prof = exp(-($u / $hw) ** 2 / BEAM_SOFT);
                $throw = (BEAM_REF / (BEAM_REF + $s)) ** 1.18;
                $light = $prof * $throw * $mo['lamp'] * $mo['flick'] * $mo['breath'];
                // The screen is an opaque plane: light exists in front of it,
                // and only inside the picture area once it reaches it.
                if ($x > SCR['x1'] || ($x >= SCR['x0'] && ($y < SCR['y0'] || $y > SCR['y1']))) {
                    $light = 0.0;
                }
                if ($light > 0.0) {
                    $c = $inScreen
                        ? pbAdd($c, $pool, 0.52 * $light)
                        : pbAdd($c, $air, 0.80 * $light);
                }
            }
            // --- projected title (clipped to the screen, graded across the throw)
            $sy = $y + $mo['jy'];
            $sx = $x + $mo['jx'];
            if ($inScreen && $sy >= $py0 && $sy < $py0 + $peh && $sx >= $px0 && $sx < $px0 + $pew) {
                $img = $plate[$sy][$sx];
                if ($img > 0.004) {
                    $grade = 1.02 - 0.30 * ($x - $px0) / max(1, $pew) - 0.055 * abs($y - ($py0 + $peh / 2)) / ($peh / 2);
                    $g = $img * $grade * $mo['lamp'] * $mo['flick'] * $mo['breath'];
                    $c = lkMix($c, $letterC, min(1.0, $g * 1.06));
                    $c = pbAdd($c, $halo, 0.30 * max(0.0, $g - 0.25));
                }
            }
            // --- film grain inside the light only
            if ($light > 0.05 || $inScreen) {
                $c = pbAdd($c, [255, 255, 255], 0.0);
                $n = (lkNoise($x, $y, $seed + 7) - 0.5) * 8.5 * min(1.0, $light * 2.2 + 0.22);
                $c = array_map(static fn (int $v): int => max(0, min(255, $v + (int) round($n))), $c);
            }
            $row[$x] = $c;
        }
        $px[$y] = $row;
    }

    // --- dust motes (additive splats, only where the cone is lit)
    $Lmax = hypot($px0 + $pew - LENS['x'], $py0 + $peh / 2 - LENS['y']) + 8;
    foreach (pbMotes() as $m) {
        $along = fmod($m['u'] + ($t === null ? 0.31 : $t * $m['spd']), 1.0);
        $s = 5.0 + $along * $Lmax;
        $hw = 1.5 + $s * $tan;
        $u = $m['v'] * $hw * 0.9 + 1.4 * sin($s * 0.11 + $m['ph']) * ($t === null ? 0 : 1) * 0.6;
        $cxp = LENS['x'] + $dx * $s - $dy * $u;
        $cyp = LENS['y'] + $dy * $s + $dx * $u;
        if ($cxp < SCR['x0'] - 22) {
            continue;                                                       // behind the lamp
        }
        if ($cxp > SCR['x1'] - 0.5) {
            continue;                                                       // past the screen plane
        }
        if ($cxp > SCR['x0'] && ($cyp < SCR['y0'] - 0.5 || $cyp > SCR['y1'] + 0.5)) {
            continue;                                                       // the frame edges clip the throw
        }
        $tw = 0.55 + 0.45 * sin(($t ?? 2.4) * $m['tw'] + $m['ph']);
        $b = $m['lamp'] ?? 1.0;
        $b = 0.80 * $mo['lamp'] * $mo['flick'] * $tw * ($m['sz'] > 0.9 ? 1.0 : 0.72)
            * (BEAM_REF / (BEAM_REF + $s)) ** 0.6;
        if ($b < 0.1) {
            continue;
        }
        $r = $m['sz'] > 0.9 ? 1.0 : 0.0;
        for ($y = (int) floor($cyp - 1 - $r); $y <= (int) ceil($cyp + 1 + $r); $y++) {
            if ($y < 0 || $y >= PH) {
                continue;
            }
            for ($x = (int) floor($cxp - 1 - $r); $x <= (int) ceil($cxp + 1 + $r); $x++) {
                if ($x < 0 || $x >= PW) {
                    continue;
                }
                $dd = (($x - $cxp) ** 2 + ($y - $cyp) ** 2);
                $w = exp(-$dd / (1.05 + $r * 1.7));
                if ($w < 0.06) {
                    continue;
                }
                $px[$y][$x] = pbAdd($px[$y][$x], $dust, 0.92 * $b * $w);
            }
        }
    }

    // --- projector silhouette (occludes the beam behind it)
    pbBody($px);
    // --- hot lens aperture + small starburst flare
    $hot = $mo['lamp'] * min(1.0, $mo['flick']);
    foreach ([[0, 0, 1.0], [1, 0, 0.55], [-1, 0, 0.45], [0, 1, 0.5], [0, -1, 0.5], [2, 0, 0.22], [-2, 0, 0.18]] as [$ox, $oy, $w]) {
        $ix = (int) round(LENS['x']) + $ox;
        $iy = (int) round(LENS['y']) + $oy;
        if ($ix < 0 || $ix >= PW || $iy < 0 || $iy >= PH) {
            continue;
        }
        $px[$iy][$ix] = lkMix($px[$iy][$ix], lkHex(C_LENS), $w * $hot);
    }
    $fl = (int) round(LENS['x']);
    for ($k = 1; $k <= 4; $k++) {                              // horizontal smear to the left
        $ix = $fl - $k;
        if ($ix >= 0) {
            $px[(int) round(LENS['y'])][$ix] = lkMix($px[(int) round(LENS['y'])][$ix], lkHex(C_LENS), 0.30 / $k * $hot);
        }
    }

    // --- film scratch (bright vertical streak across the projected frame)
    if ($mo['scratch'] !== null) {
        $sx0 = $mo['scratch'];
        foreach ([$sx0 - 1, $sx0, $sx0 + 1] as $i => $cx2) {
            $w = ($i === 1 ? 1.0 : 0.32) * (0.35 + 0.5 * $mo['settle']);
            for ($y = SCR['y0'] + 1; $y <= SCR['y1'] - 1; $y++) {
                if ($cx2 < SCR['x0'] || $cx2 > SCR['x1']) {
                    continue;
                }
                $fade = 0.45 + 0.55 * lkNoise($y, $sx0, SEED);
                $px[$y][$cx2] = pbAdd($px[$y][$cx2], $dust, 0.5 * $w * $fade);
            }
        }
    }

    // --- vignette: darken toward the frame corners
    foreach ($px as $y => &$row) {
        foreach ($row as $x => &$c) {
            $vx = ($x - (PW - 1) / 2) / (PW * 0.62);
            $vy = ($y - (PH - 1) / 2) / (PH * 0.60);
            $v = 1.0 - 0.34 * min(1.0, $vx * $vx + $vy * $vy);
            $c = [(int) round($c[0] * $v), (int) round($c[1] * $v), (int) round($c[2] * $v)];
        }
    }
    unset($row, $c);

    // --- screen top batten + masked trim (drawn last so it stays crisp)
    for ($x = SCR['x0']; $x <= SCR['x1']; $x++) {
        $tint = 0.35 + 0.65 * max(0.0, 1.0 - abs($x - ($px0 + $pew / 2)) / 34.0);
        $px[SCR['y0'] - 1][$x] = pbAdd(lkHex(C_THEATER_TOP), lkHex(C_BATTEN), $tint);
    }
    return $px;
}

/** Projector body, twin film reels, lens barrel — dark silhouette with lit edges. */
function pbBody(array &$px): void
{
    $bodyLIT = lkHex(C_BODY_LIT);
    $body = lkHex(C_BODY);
    $reel = lkHex(C_REEL);
    $reelLIT = lkHex(C_REEL_LIT);
    // body: rounded box cols 2..14, rows 19..24
    for ($y = 19; $y <= 24; $y++) {
        for ($x = 2; $x <= 14; $x++) {
            $clipTL = ($y === 19 && $x <= 3) || ($x === 2 && $y === 19);
            $clipBR = $y === 24 && $x >= 13;
            if ($clipTL || $clipBR) {
                continue;
            }
            $top = $y === 19 ? 0.9 : ($y === 20 ? 0.25 : 0.0);
            $px[$y][$x] = lkMix($px[$y][$x], $body, 1.0);
            if ($top > 0) {
                $px[$y][$x] = lkMix($px[$y][$x], $bodyLIT, $top);
            }
            if ($x === 14) {                                   // beam-side edge catches the lamp
                $px[$y][$x] = lkMix($px[$y][$x], $bodyLIT, 0.45);
            }
        }
    }
    // lens barrel: cols 14..17 rows 20..21
    for ($y = 20; $y <= 21; $y++) {
        for ($x = 14; $x <= 16; $x++) {
            $px[$y][$x] = lkMix($px[$y][$x], $body, 1.0);
            if ($x === 16) {
                $px[$y][$x] = lkMix($px[$y][$x], $bodyLIT, 0.5);
            }
        }
    }
    // two feed reels above the body (rings)
    foreach ([['cx' => 6.0, 'cy' => 14.5, 'r' => 3.4], ['cx' => 12.5, 'cy' => 15.5, 'r' => 2.6]] as $n => $re) {
        for ($y = (int) floor($re['cy'] - $re['r'] - 1); $y <= (int) ceil($re['cy'] + $re['r']); $y++) {
            if ($y < 0 || $y >= PH) {
                continue;
            }
            for ($x = (int) floor($re['cx'] - $re['r'] - 1); $x <= (int) ceil($re['cx'] + $re['r']); $x++) {
                if ($x < 0 || $x >= PW) {
                    continue;
                }
                $d = hypot($x - $re['cx'], ($y - $re['cy']) * 1.0);
                if ($d <= $re['r'] - 1.1) {
                    $spoke = ($n === 0 && (($x + $y) % 3 === 0)) ? 0.5 : 0.0;
                    $px[$y][$x] = lkMix($px[$y][$x], $reel, 1.0);
                    if ($spoke > 0) {
                        $px[$y][$x] = lkMix($px[$y][$x], $reelLIT, $spoke);
                    }
                } elseif ($d <= $re['r']) {
                    $lit = max(0.0, min(1.0, ($x - $re["cx"]) / $re["r"])) * 0.8 + 0.2;
                    $px[$y][$x] = lkMix($px[$y][$x], $reelLIT, $lit);
                }
            }
        }
        // hub
        $hy = (int) round($re['cy']);
        $hx = (int) round($re['cx']);
        $px[$hy][$hx] = lkMix($px[$hy][$hx], $reelLIT, 0.9);
    }
}

// ================================================================ CELLS

function pbCells(?float $t): array
{
    return lkHalfBlock(pbScene($t));
}

// ================================================================ OUTPUT

function pbEncode(float|string|null $t = null): string
{
    return lkEncode(pbCells(is_float($t) || is_int($t) ? (float) $t : null), 'tc');
}

function pbAnim(): string
{
    $frames = [];
    for ($f = 0; $f < FRAMES; $f++) {
        $frames[] = lkEncode(pbCells((float) $f), 'tc');
    }
    return lkAnim($frames, pbEncode());
}

$write = in_array('--write', $argv, true);
$play = in_array('--anim', $argv, true);
$frameArg = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--frame=')) {
        $frameArg = (int) substr($a, 8);
    }
}
$dir = __DIR__;

if ($frameArg !== null) {
    echo lkEncode(pbCells((float) $frameArg), 'tc');
    exit(0);
}

$static = pbEncode();
[$w, $h] = lkVerify($static, 'static');
lkCheckDepth($static, 'tc', 'static');
fwrite(STDERR, sprintf("static %dx%d  truecolor ok  frames=%d\n", $w, $h, FRAMES));
echo $static;

if ($write) {
    file_put_contents("$dir/logo-projector-beam-tc.ansi", $static);
    $anim = pbAnim();
    [$aw, $ah] = lkVerify(lkLastFrame($anim), 'anim/last');
    lkCheckDepth($anim, 'tc', 'anim');
    if ($aw !== $w || $ah !== $h) {
        throw new RuntimeException("anim last frame {$aw}x{$ah} != static {$w}x{$h}");
    }
    $sep = "\e[{$h}A\r";
    $nf = 1 + substr_count($anim, $sep);
    file_put_contents("$dir/logo-projector-beam-anim-tc.ansi", $anim);
    fwrite(STDERR, sprintf(
        "wrote logo-projector-beam-tc.ansi (%d B) + logo-projector-beam-anim-tc.ansi (%d B, %d frames, ~%.1fs @%dms)\n",
        strlen($static), strlen($anim), $nf, $nf * FRAME_MS / 1000, FRAME_MS,
    ));
}

if ($play) {
    $tmp = "$dir/.play-preview.ansi";
    file_put_contents($tmp, $anim ?? pbAnim());
    passthru('php ' . escapeshellarg(__DIR__ . '/logo-play.php') . ' ' . escapeshellarg($tmp) . ' ' . FRAME_MS);
    unlink($tmp);
    echo "\n";
}

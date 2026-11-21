<?php

declare(strict_types=1);

/**
 * generate-oscilloscope-signature — "Phlix" signed by an oscilloscope beam.
 *
 * Concept #7: a single glowing beam strokes across the scope face and writes
 * "Phlix" as ONE continuous signature (P-h-l-i-x joined by baseline connectors,
 * closed by a flourish curl). Behind the beam head a phosphor trail flares
 * white-hot and decays toward the settled trace; when the signature completes,
 * the whole trace relaxes into the final logo — a permanently glowing amber
 * tube on a faint dot graticule. Monochromatic amber glow, half-block (▀)
 * sub-pixel shading for smooth curves and bloom falloff.
 *
 * Mechanics (own design, not the phosphor-scope-trace exemplar):
 *   - Signature waypoints → centripetal Catmull-Rom → dense samples with
 *     cumulative arc length L.
 *   - One precompute: for every px, (dmin, smin) = distance to the path and
 *     the arc length where the beam first painted it.
 *   - Frame at reveal P(t): age = P + settleBoost(t) − smin; level
 *     g = STEADY + (1−STEADY)·e^(−age/TAU) → fresh trail burns bright, the
 *     past decays; g at end-of-animation ≈ static frame level (the static IS
 *     the steady state — no brightness jump).
 *   - Beam head: white-hot dot + amber halo that lights blank screen ahead.
 *   - px grid 64×24 → lkHalfBlock → cells 64×12 → lkEncode('tc').
 *
 * Usage:
 *   php generate-oscilloscope-signature.php           preview + verify to stdout
 *   php generate-oscilloscope-signature.php --write   write the .ansi files
 *   php logo-play.php logo-oscilloscope-signature-anim-tc.ansi 60
 *   php logo-preview.php logo-oscilloscope-signature-tc.ansi logo-oscilloscope-signature-tc.png
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const W = 64;               // cells (columns)
const H = 12;               // cells (rows)
const PX_W = W;             // one px per column (half-block: 2 px per row)
const PX_H = H * 2;         // 24 px rows

const ANIM_FRAMES = 60;     // + static; logo-play at 60 ms → ~3.6 s

// phosphor palette — amber tube, monochromatic glow
const SCR_CENTRE = '#0D0705';
const SCR_EDGE   = '#020101';
const GRID_C     = '#3A2208';
const BODY_C     = '#FF9E2C';   // trace body
const BLOOM_C    = '#8F4E12';   // outer bloom
const CORE_C     = '#FFEDCB';   // hot filament
const BEAM_C     = '#FFF7E4';   // beam dot

const STEADY = 0.66;        // settled trail level (static frame renders at this)
const TAU    = 10.0;        // trail persistence, px of arc length
const WRITE_END = 0.86;     // fraction of the timeline when the pen lifts

/**
 * The signature: one continuous CURSIVE pen path spelling "Phlix", waypoints
 * in px space (y down; ascender ≈3.6, x-height ≈10.6, baseline ≈18.4).
 * Geometry law (learned the hard way): every parallel pass keeps ≥1.8 px
 * clearance and no two consecutive waypoints sit <1.5 px apart, or the
 * Catmull-Rom meanders and the trace blobs. P: lead-in, bowl loop closing on
 * the stem top, stem down. h: ascender hairpin, down, arch, leg. l: tall
 * rounded hairpin. i: short hairpin at x-height. x: rise to the TR apex, hook
 * left over it, cross down to BR. Flourish: baseline wave, whip up-right,
 * terminal spiral curl.
 */
const SIGNATURE = [
    // — lead-in + P (bowl loop, then stem) —
    [7.0, 18.6], [8.8, 15.2], [10.2, 12.0], [11.6, 11.0], [13.6, 9.2],
    [15.0, 6.8], [15.2, 4.8], [13.6, 3.8], [11.8, 4.0], [11.0, 5.2],
    [10.8, 8.0], [10.4, 11.6], [10.1, 15.0], [10.0, 17.4], [10.8, 18.6], [12.4, 18.6],
    // — h (starts clear of the P bowl rim) —
    [15.8, 14.6], [16.5, 10.0], [17.1, 5.8], [17.9, 3.9], [18.9, 4.2],
    [19.4, 7.6], [19.7, 11.6], [19.9, 15.6], [20.0, 18.4],
    [21.0, 14.8], [22.2, 11.8], [23.6, 10.8], [24.8, 11.8], [25.6, 14.8], [26.1, 18.6],
    // — l —
    [27.0, 14.4], [27.7, 9.0], [28.3, 5.0], [29.1, 3.7],
    [30.0, 4.6], [30.3, 8.2], [30.5, 13.0], [30.4, 16.8], [30.3, 18.6],
    // — i —
    [31.1, 16.0], [31.8, 12.8], [32.5, 11.0], [33.5, 10.6],
    [34.3, 12.3], [34.5, 14.8], [34.6, 17.0], [34.5, 18.6],
    // — x (rise, apex hook, crossing descent) —
    [35.4, 17.8], [36.6, 15.0], [38.6, 11.6], [39.5, 10.6],
    [37.6, 11.0], [38.9, 14.0], [40.3, 16.9], [41.6, 18.4],
    // — flourish (wave, whip, spiral curl) —
    [43.2, 18.6], [46.2, 18.0], [49.0, 18.7], [51.8, 17.9], [54.4, 16.2],
    [56.8, 13.4], [58.4, 10.6], [58.8, 8.0], [57.6, 6.2], [55.5, 5.9], [53.9, 7.2], [53.3, 9.2],
];

// ================================================================ PATH

/**
 * Centripetal Catmull-Rom (alpha=0.5) through the waypoints, resampled to a
 * uniform ~0.55 px. Returns [pts, cum] with pts[i]=[x,y], cum[i]=arc length.
 */
function signatureSamples(): array
{
    $wp = SIGNATURE;
    $n = count($wp);
    $dense = [];
    for ($i = 0; $i < $n - 1; $i++) {
        [$p0, $p1, $p2, $p3] = [
            $wp[max(0, $i - 1)], $wp[$i], $wp[$i + 1], $wp[min($n - 1, $i + 2)],
        ];
        $knot = [0.0];
        $chain = [$p0, $p1, $p2, $p3];
        for ($j = 1; $j < 4; $j++) {
            $dd = hypot($chain[$j][0] - $chain[$j - 1][0], $chain[$j][1] - $chain[$j - 1][1]);
            $knot[$j] = $knot[$j - 1] + max(1e-5, $dd) ** 0.5;
        }
        [$t0, $t1, $t2, $t3] = $knot;
        $steps = 14;
        for ($k = 0; $k < $steps; $k++) {
            $t = $t1 + ($t2 - $t1) * $k / $steps;
            // centripetal de Boor chain: A(t) between knots, B(t) re-mix, C(t) final
            $a1 = [($t1 - $t) / max(1e-6, $t1 - $t0) * ($p0[0]) + ($t - $t0) / max(1e-6, $t1 - $t0) * ($p1[0]),
                ($t1 - $t) / max(1e-6, $t1 - $t0) * ($p0[1]) + ($t - $t0) / max(1e-6, $t1 - $t0) * ($p1[1])];
            $a2 = [($t2 - $t) / max(1e-6, $t2 - $t1) * ($p1[0]) + ($t - $t1) / max(1e-6, $t2 - $t1) * ($p2[0]),
                ($t2 - $t) / max(1e-6, $t2 - $t1) * ($p1[1]) + ($t - $t1) / max(1e-6, $t2 - $t1) * ($p2[1])];
            $a3 = [($t3 - $t) / max(1e-6, $t3 - $t2) * ($p2[0]) + ($t - $t2) / max(1e-6, $t3 - $t2) * ($p3[0]),
                ($t3 - $t) / max(1e-6, $t3 - $t2) * ($p2[1]) + ($t - $t2) / max(1e-6, $t3 - $t2) * ($p3[1])];
            $b1 = [($t2 - $t) / max(1e-6, $t2 - $t0) * ($a1[0]) + ($t - $t0) / max(1e-6, $t2 - $t0) * ($a2[0]),
                ($t2 - $t) / max(1e-6, $t2 - $t0) * ($a1[1]) + ($t - $t0) / max(1e-6, $t2 - $t0) * ($a2[1])];
            $b2 = [($t3 - $t) / max(1e-6, $t3 - $t1) * ($a2[0]) + ($t - $t1) / max(1e-6, $t3 - $t1) * ($a3[0]),
                ($t3 - $t) / max(1e-6, $t3 - $t1) * ($a2[1]) + ($t - $t1) / max(1e-6, $t3 - $t1) * ($a3[1])];
            $dense[] = [($t2 - $t) / max(1e-6, $t2 - $t1) * ($b1[0]) + ($t - $t1) / max(1e-6, $t2 - $t1) * ($b2[0]),
                ($t2 - $t) / max(1e-6, $t2 - $t1) * ($b1[1]) + ($t - $t1) / max(1e-6, $t2 - $t1) * ($b2[1])];
        }
    }
    $dense[] = $wp[$n - 1];

    // march the dense polyline at a uniform ~0.55 px probe
    $pts = [$dense[0]];
    $step = 0.55;
    $need = $step;
    $walked = 0.0;
    for ($i = 1; $i < count($dense); $i++) {
        [$x0, $y0] = $dense[$i - 1];
        [$x1, $y1] = $dense[$i];
        $seg = hypot($x1 - $x0, $y1 - $y0);
        if ($seg < 1e-9) {
            continue;
        }
        while ($need <= $seg) {
            $f = $need / $seg;
            $pts[] = [$x0 + ($x1 - $x0) * $f, $y0 + ($y1 - $y0) * $f];
            $need += $step;
        }
        $need -= $seg;
        $walked += $seg;
    }
    $pts[] = $dense[count($dense) - 1];

    $cum = [0.0];
    $acc = 0.0;
    for ($i = 1; $i < count($pts); $i++) {
        $acc += hypot($pts[$i][0] - $pts[$i - 1][0], $pts[$i][1] - $pts[$i - 1][1]);
        $cum[] = $acc;
    }
    return [$pts, $cum];
}

/**
 * Per-px fields: dist[y][x] = distance to the path; smin[y][x] = arc length
 * at the nearest path point (when the beam first painted this px).
 */
function signatureField(array $pts, array $cum): array
{
    $dist = $smin = [];
    $m = count($pts);
    for ($y = 0; $y < PX_H; $y++) {
        for ($x = 0; $x < PX_W; $x++) {
            $px = $x + 0.5;
            $py = $y + 0.5;
            $best = INF;
            $bs = 0.0;
            for ($i = 0; $i < $m; $i++) {
                $dx = $pts[$i][0] - $px;
                if ($dx > 4 || $dx < -4) {
                    continue;
                }
                $d = $dx * $dx + ($pts[$i][1] - $py) ** 2;
                if ($d < $best) {
                    $best = $d;
                    $bs = $cum[$i];
                }
            }
            $dist[$y][$x] = sqrt($best);
            $smin[$y][$x] = $bs;
        }
    }
    return [$dist, $smin, $cum[count($cum) - 1]];
}

/** Point on the path at arc length s (for the beam head). */
function pathPoint(array $pts, array $cum, float $s): array
{
    $lo = 0;
    $hi = count($cum) - 1;
    while ($lo < $hi - 1) {
        $mid = intdiv($lo + $hi, 2);
        if ($cum[$mid] <= $s) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }
    $span = max(1e-6, $cum[$hi] - $cum[$lo]);
    $f = max(0.0, min(1.0, ($s - $cum[$lo]) / $span));
    return [
        $pts[$lo][0] + ($pts[$hi][0] - $pts[$lo][0]) * $f,
        $pts[$lo][1] + ($pts[$hi][1] - $pts[$lo][1]) * $f,
    ];
}

// ================================================================ RENDER

/** Screen: dark radial scope face + dot graticule + faint centre lines. */
function screenPx(int $x, int $y): array
{
    $dx = ($x + 0.5 - PX_W / 2) / (PX_W / 2);
    $dy = ($y + 0.5 - PX_H / 2) / (PX_H / 2 + 0.5);
    $r = min(1.0, sqrt($dx * $dx * 0.75 + $dy * $dy));
    $c = lkMix(lkHex(SCR_CENTRE), lkHex(SCR_EDGE), $r ** 1.3);

    $grid = 0.0;
    if ($x === intdiv(PX_W, 2)) {
        $grid = 0.040;                             // centre column
    }
    if ($y === intdiv(PX_H, 2)) {
        $grid = max($grid, 0.036);                 // centre row
    }
    if ($x % 8 === 0 && $x !== 0 && $x !== PX_W - 1 && abs($y - PX_H / 2) < 2.0) {
        $grid = 0.10;                              // x ticks straddling centre row
    }
    if ($y % 4 === 0 && $y !== intdiv(PX_H, 2) && abs($x - PX_W / 2) < 2.0) {
        $grid = 0.10;                              // y ticks straddling centre col
    }
    return $grid > 0.0 ? lkMix($c, lkHex(GRID_C), $grid) : $c;
}

/**
 * One px of one frame. $reveal = arc length reached; $settle = extra age
 * after pen lift; $head = [x,y]|null beam position; $flicker noise seed.
 */
function pixel(array $dist, array $smin, int $x, int $y, float $reveal, float $settle, ?array $head, int $flick): array
{
    $c = screenPx($x, $y);
    $d = $dist[$y][$x];

    $age = $reveal - $smin[$y][$x] + $settle;
    if ($age >= 0) {
        $g = STEADY + (1 - STEADY) * exp(-$age / TAU);
        // thin bright filament: body dies by d≈1.5 px, bloom is a whisper,
        // core rides only the freshest/hottest part of the trail
        $body = max(0.0, 1 - max(0.0, $d - 0.35) / 1.15) ** 1.25 * $g;
        $bloom = 0.30 * exp(-max(0.0, $d - 0.9) / 1.6) * $g;
        $c = lkMix($c, lkHex(BLOOM_C), min(1.0, $bloom));
        $c = lkMix($c, lkHex(BODY_C), min(1.0, $body * 0.95));
        if ($d < 1.0) {
            $ck = (1 - $d) ** 1.3 * $g * 0.95;
            $c = lkMix($c, lkHex(CORE_C), $ck);
        }
    }

    if ($head !== null) {
        $hd = hypot($x + 0.5 - $head[0], $y + 0.5 - $head[1]);
        $c = lkMix($c, lkHex(BLOOM_C), 0.40 * exp(-$hd / 2.6));
        $c = lkMix($c, lkHex(CORE_C), 0.28 * exp(-$hd / 1.6));
        if ($hd < 1.3) {
            $c = lkMix($c, lkHex(BEAM_C), (1 - $hd / 1.3) * 0.95);
        }
    }

    if ($y % 2 === 1) {
        $c = lkScale($c, 0.955);                   // CRT scanline
    }
    return lkScale($c, 0.97 + 0.06 * lkNoise($x, $y, $flick)); // gentle phosphor flicker
}

/** Full cell grid for a moment $t ∈ [0,1] on the timeline. */
function frame(array $field, array $path, float $t): array
{
    [$dist, $smin, $len] = $field;
    [$pts, $cum] = $path;
    $u = max(0.0, min(1.0, $t / WRITE_END));
    $reveal = $len * (3 * $u * $u - 2 * $u * $u * $u);           // smoothstep pen motion
    $settle = $t > WRITE_END ? 30.0 * ($t - WRITE_END) / (1 - WRITE_END) : 0.0;
    $head = ($t > 0.02 && $t < WRITE_END) ? pathPoint($pts, $cum, $reveal) : null;

    $px = [];
    for ($y = 0; $y < PX_H; $y++) {
        for ($x = 0; $x < PX_W; $x++) {
            $px[$y][$x] = pixel($dist, $smin, $x, $y, $reveal, $settle, $head, $head === null ? 91 : 7);
        }
    }
    return lkHalfBlock($px);
}

/** Static logo: the fully settled trace (g → STEADY… rendered at its resting look). */
function staticFrame(array $field): array
{
    [$dist, $smin, $len] = $field;
    $px = [];
    for ($y = 0; $y < PX_H; $y++) {
        for ($x = 0; $x < PX_W; $x++) {
            $px[$y][$x] = pixel($dist, $smin, $x, $y, $len, 30.0, null, 91);
        }
    }
    return lkHalfBlock($px);
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
[$pts, $cum] = signatureSamples();
$field = signatureField($pts, $cum);

$staticCells = staticFrame($field);
$static = lkEncode($staticCells, 'tc');
[$w, $h] = lkVerify($static, 'static');
lkCheckDepth($static, 'tc', 'static');

$frames = [];
for ($f = 0; $f < ANIM_FRAMES; $f++) {
    $frames[] = lkEncode(frame($field, [$pts, $cum], $f / (ANIM_FRAMES - 1)), 'tc');
}
$anim = lkAnim($frames, $static);
lkCheckDepth($anim, 'tc', 'anim');
foreach ($frames as $i => $fr) {
    lkVerify($fr, "frame $i");
}

if ($write) {
    file_put_contents(__DIR__ . '/logo-oscilloscope-signature-tc.ansi', $static);
    file_put_contents(__DIR__ . '/logo-oscilloscope-signature-anim-tc.ansi', $anim);
    fwrite(STDERR, "wrote logo-oscilloscope-signature-tc.ansi + logo-oscilloscope-signature-anim-tc.ansi\n");
} else {
    echo $static;
    fwrite(STDERR, "[tc] {$w}x{$h} ok — " . count($frames) . " frames, path len " . round($cum[count($cum) - 1]) . " px, " . count($pts) . " samples\n");
}

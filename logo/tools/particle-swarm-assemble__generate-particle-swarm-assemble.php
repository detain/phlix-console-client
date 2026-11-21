<?php

declare(strict_types=1);

/**
 * generate-particle-swarm-assemble — Phlix console logo, seat 17.
 *
 * Concept: Particle Swarm Assemble. A murmuration of amber dust swirls on a
 * sheared vortex (letters coiled into a spiral: inner ring = "P", outer =
 * "x"), then gets magnetically snapped into the "Phlix" wordmark — each mote
 * springs to its pixel with an overshoot + arrival glint, settling solid.
 * The outro is the same mechanics reversed: the letters squeeze, burst back
 * into the vortex, and dissolve out — for app quit.
 *
 * Mechanics (deterministic, seeded LCG — same every run):
 *   - wordmark mask  : 40x12 px built from rect/diagonal stamps, half-blocked
 *   - particles      : one per ink pixel, z-depth 0..1 (far=dim+small 1px,
 *                      near=bright+full-cell 2px), flight colour = home tint
 *                      scaled by depth with a sine flicker
 *   - swirl phase    : elliptical orbits, omega ~ 1/(r+6) so the coil shears,
 *                      radius breathing + drifting vortex centre = curved flow
 *   - snap phase     : easeOutBack lerp to home, radial overshoot past target
 *                      + tangential streak along orbit velocity, 1-2 frame
 *                      glint on arrival (the "settle")
 *   - outro          : 2-frame squeeze (easeInBack), burst to the (lead) orbit,
 *                      swirl, fade-to-black dissolve, blank resting frame
 *
 * Usage:
 *   php generate-particle-swarm-assemble.php           # build + verify + write all files
 *   php generate-particle-swarm-assemble.php --dump    # print static frame to stdout
 *   php generate-particle-swarm-assemble.php --frame=N # print intro timeline frame N to stdout
 *   php generate-particle-swarm-assemble.php --outro --frame=N
 *                                                      # print outro timeline frame N to stdout
 *
 * Outputs (all inside this directory):
 *   logo-particle-swarm-assemble-anim-tc.ansi    intro animation (PRIMARY)
 *   logo-particle-swarm-assemble-outro-tc.ansi   outro animation (app quit)
 *   logo-particle-swarm-assemble-tc.ansi         static assembled frame
 *
 * Play:  php logo-play.php logo-particle-swarm-assemble-anim-tc.ansi 70
 * PNG:   php logo-preview.php logo-particle-swarm-assemble-tc.ansi logo-particle-swarm-assemble-tc.png
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'particle-swarm-assemble';
const SEED = 9017;                 // seat 17, deterministic

const CW = 64;                     // terminal columns  (<= 80)
const CH = 12;                     // terminal rows     (<= 14)
const PW = CW;                     // pixel-grid width
const PH = CH * 2;                 // pixel-grid height (half-block)

const N_INTRO = 60;                // animation frames (static frame appended)
const N_OUTRO = 60;

const WM_W = 40;                   // wordmark width  in px
const WM_H = 12;                   // wordmark height in px
const PX0 = (CW - WM_W) >> 1;      // wordmark origin on the pixel grid
const PY0 = (PH - WM_H) >> 1;

/** Amber-only palette, left → right across the wordmark. No rainbow. */
const GRAD = ['#FFD9A3', '#FFB454', '#EF8A1C', '#C9691A'];
const SHADOW = '#48220A';

// Intro timeline
const T_SNAP0 = 24.0;              // first particles let go of the vortex here
// Outro timeline
const O_FADE0 = 40.0;              // dissolve window start
const O_FADE1 = 52.0;              // last visible frame of the dust

// ================================================================ DETERMINISM

/** Seedable LCG (Park-Miller style, 31-bit) — never unseeded rand(). */
final class Drng
{
    private int $s;

    public function __construct(int $seed)
    {
        $this->s = ($seed * 2654435761 + 12345) & 0x7FFFFFFE | 1;
    }

    public function f(): float
    {
        $this->s = ($this->s * 1103515245 + 12345) & 0x7FFFFFFF;
        return $this->s / 0x80000000;
    }
}

// ================================================================ WORDMARK

/**
 * "Phlix" mask, 12 px tall (P ascender+bowl, h ascender+shoulder+leg,
 * l stem+foot, i dot+stem+base, x crossed diagonals), 40 px wide total.
 * @return array{0: list<string>, 1: list<array{int,int}>} [rows, ink coords]
 */
function buildInk(): array
{
    $g = array_fill(0, WM_H, str_repeat('.', WM_W));
    $stamp = static function (int $x, int $y, int $w, int $h) use (&$g): void {
        for ($yy = $y; $yy < $y + $h; $yy++) {
            if ($yy < 0 || $yy >= WM_H) {
                continue;
            }
            $row = str_split($g[$yy]);
            for ($xx = $x; $xx < $x + $w; $xx++) {
                if ($xx >= 0 && $xx < WM_W) {
                    $row[$xx] = '#';
                }
            }
            $g[$yy] = implode('', $row);
        }
    };
    // P                                                    h
    $stamp(0, 0, 2, 12); $stamp(2, 0, 5, 2);                 $stamp(10, 0, 2, 12); $stamp(12, 4, 5, 2); $stamp(16, 6, 2, 6);
    $stamp(6, 2, 2, 2);  $stamp(2, 4, 5, 2);
    // l                                  i
    $stamp(21, 0, 2, 10); $stamp(20, 10, 4, 2);              $stamp(27, 0, 2, 2); $stamp(27, 4, 2, 6); $stamp(26, 10, 4, 2);
    // x  (two 2px diagonals meeting mid-stroke)
    for ($s = 0; $s <= 6; $s++) {
        $stamp(32 + $s, 4 + $s, 2, 2);
        $stamp(38 - $s, 4 + $s, 2, 2);
    }
    $ink = [];
    foreach ($g as $y => $row) {
        foreach (str_split($row) as $x => $c) {
            if ($c === '#') {
                $ink[] = [$x, $y];
            }
        }
    }
    return [$g, $ink];
}

/** Final assembled colour of one wordmark pixel (amber gradient + light + grain). */
function finalTint(int $col, int $row): array
{
    $c = lkGradient(GRAD, $col / (WM_W - 1));
    $c = lkShade($c, 0.18 - 0.30 * $row / (WM_H - 1));                  // lit top, darker base
    return lkShade($c, 0.10 * (lkNoise($col, $row, 7) - 0.5));          // faint dust grain
}

/** The resting logo as a pixel grid: ink + classic half-block drop shadow. */
function staticPixels(array $ink): array
{
    $px = [];
    for ($y = 0; $y < PH; $y++) {
        $px[$y] = array_fill(0, PW, null);
    }
    foreach ($ink as [$col, $row]) {
        $px[PY0 + $row][PX0 + $col] = finalTint($col, $row);
    }
    foreach ($ink as [$col, $row]) {                                    // offset (-1,+1) like the house template
        $yy = PY0 + $row + 1;
        $xx = PX0 + $col - 1;
        if ($yy < PH && $xx >= 0 && $px[$yy][$xx] === null) {
            $px[$yy][$xx] = lkHex(SHADOW);
        }
    }
    return $px;
}

// ================================================================ PARTICLES

/** One mote per ink pixel. Returned sorted far → near (painter's order). */
function makeParticles(array $ink, int $seed): array
{
    $rng = new Drng($seed);
    $ps = [];
    foreach ($ink as $i => [$col, $row]) {
        $t = $col / (WM_W - 1);
        $z = 0.10 + 0.90 * $rng->f();
        $ps[] = [
            'i'    => $i,
            'home' => [PX0 + $col, PY0 + $row],
            'tint' => finalTint($col, $row),
            'z'    => $z,
            // spiral coil: inner ring starts at "P", sweeps 1.25 turns to "x"
            'r'    => 5.0 + $t * 17.0 + $rng->f() * 7.0,
            'th0'  => $t * (M_PI * 2.5) + $row * 0.10 + $rng->f() * 0.30,
            'jp'   => $rng->f() * M_PI * 2,
            'st'   => T_SNAP0 + $rng->f() * 7.0 - $z * 2.0,             // near motes land first
            'dur'  => 13.0 + $rng->f() * 6.0,
            'ost'  => 2.0 + $rng->f() * 6.0 + $z * 3.0,                 // outro depart beat
            'odur' => 15.0 + $rng->f() * 4.0,
        ];
    }
    usort($ps, static fn (array $a, array $b): int => $a['z'] <=> $b['z']);
    return $ps;
}

/** Position on the murmuration at frame time $t (swirl centre + sheared orbits). */
function orbitPos(array $p, float $t): array
{
    $omega = 0.0393 * (1.0 + 20.0 / ($p['r'] + 6.0));                   // inner ring shears faster
    $th = $p['th0'] + $omega * $t;
    $r = $p['r'] * (1.0 + 0.07 * sin(0.32 * $t + $p['jp']));            // breathing flock
    $cx = 32.0 + 2.6 * sin(0.105 * $t + 0.7);                           // drifting vortex centre
    $cy = 12.0 + 1.5 * sin(0.075 * $t + 2.1);
    return [$cx + $r * cos($th), $cy + 0.42 * $r * sin($th)];           // ellipse: rows are 2x px
}

function easeOutBack(float $u): float
{
    $c1 = 1.70158;
    $v = $u - 1.0;
    return 1.0 + ($c1 + 1.0) * $v ** 3 + $c1 * $v ** 2;
}

function easeInBack(float $u): float
{
    $c1 = 1.70158;
    return ($c1 + 1.0) * $u ** 3 - $c1 * $u ** 2;
}

// ================================================================ TIMELINES

/** Intro mote position: vortex until st, spring home with overshoot, then hold. */
function introPos(array $p, int $t): array
{
    if ($t < $p['st']) {
        return orbitPos($p, (float) $t);
    }
    $u = min(1.0, ($t - $p['st']) / $p['dur']);
    if ($u >= 1.0) {
        return [(float) $p['home'][0], (float) $p['home'][1]];
    }
    $a = orbitPos($p, max(0.0, $p['st'] - 0.8));                        // release point
    $b = orbitPos($p, $p['st'] + 0.8);                                  // orbit tangent
    $e = easeOutBack($u);
    $d = hypot($p['home'][0] - $a[0], $p['home'][1] - $a[1]);
    $k = min(1.0, $d / 22.0);
    $ex = $e + 0.10 * $k * max(0.0, $e - 0.90) / 0.10;                  // overshoot past home
    $tl = max(1e-6, hypot($b[0] - $a[0], $b[1] - $a[1]));
    $tk = 2.2 * $k * max(0.0, $e - 0.92) / 0.08;                        // tangential streak
    return [
        $a[0] + ($p['home'][0] - $a[0]) * $ex + ($b[0] - $a[0]) / $tl * $tk,
        $a[1] + ($p['home'][1] - $a[1]) * $ex + ($b[1] - $a[1]) / $tl * $tk,
    ];
}

/** Intro mote colour: depth-scaled flight tint, lerping to final tint on snap. */
function introColor(array $p, int $t): ?array
{
    $flick = 0.82 + 0.26 * sin(0.55 * $t + $p['jp']);
    $flight = lkScale($p['tint'], (0.30 + 0.72 * $p['z']) * $flick);
    if ($t < $p['st']) {
        return $flight;
    }
    $u = min(1.0, ($t - $p['st']) / $p['dur']);
    $c = lkMix($flight, $p['tint'], $u);
    if ($u >= 1.0 && $t <= $p['st'] + $p['dur'] + 1.0) {
        $c = lkShade($c, 0.5);                                          // arrival glint (1-2 frames)
    }
    return $c;
}

/**
 * Outro mote: hold on home (pre-shake at t=1), squeeze-and-burst back to the
 * (time-lead) orbit, swirl, then dissolve toward black. null colour = invisible.
 */
function outroState(array $p, int $t): array
{
    $e0 = $p['ost'] + $p['odur'];                                       // burst end
    if ($t <= $p['ost']) {
        $jx = 0.0;
        $jy = 0.0;
        if ($t === 1 && lkNoise($p['i'], 13, 17) < 0.35) {             // the flock shakes before bursting
            $jx = (int) round(2 * $p['jp'] / M_PI) % 3 - 1;
        }
        return [[$p['home'][0] + $jx, $p['home'][1] + $jy], $p['tint']];
    }
    if ($t < $e0) {
        $u = ($t - $p['ost']) / $p['odur'];
        $lead = orbitPos($p, (float) ($t + $p['odur']));               // moving target = smooth handoff
        $e = easeInBack($u);
        return [
            [$p['home'][0] + ($lead[0] - $p['home'][0]) * $e, $p['home'][1] + ($lead[1] - $p['home'][1]) * $e],
            introColor($p, $t),
        ];
    }
    if ($t < O_FADE0) {
        return [orbitPos($p, (float) $t), introColor($p, $t)];
    }
    if ($t >= O_FADE1) {
        return [[0.0, 0.0], null];                                      // dissolved
    }
    $sc = max(0.0, (O_FADE1 - $t) / (O_FADE1 - O_FADE0));              // fade toward black
    if ($sc < 0.22) {
        return [[0.0, 0.0], null];
    }
    $flick = 0.82 + 0.26 * sin(0.55 * $t + $p['jp']);
    return [orbitPos($p, (float) $t), lkScale($p['tint'], (0.30 + 0.72 * $p['z']) * $flick * $sc)];
}

// ================================================================ RENDER

/** Paint one frame from a particle set via pos/colour callables → encoded cells. */
function paintFrame(array $parts, callable $posOf, callable $colOf, int $t): string
{
    $px = [];
    for ($y = 0; $y < PH; $y++) {
        $px[$y] = array_fill(0, PW, null);
    }
    foreach ($parts as $p) {                                           // already sorted far → near
        [$x, $y] = $posOf($p, $t);
        $col = $colOf($p, $t);
        if ($col === null) {
            continue;
        }
        $xi = (int) round($x);
        $yi = (int) round($y);
        if ($xi < 0 || $xi >= PW || $yi < 0 || $yi >= PH) {
            continue;                                                  // off-canvas dust is dropped
        }
        $px[$yi][$xi] = $col;
        if ($p['z'] > 0.62 && $yi + 1 < PH) {
            $px[$yi + 1][$xi] = $col;                                  // near motes read as full cells
        }
    }
    return lkEncode(lkHalfBlock($px), 'tc');
}

/**
 * Intro colour fn wrapper so far/near SIZE comes from paint; here size is z-based,
 * colour per-particle from the timeline fn.
 */
function introColorOf(array $p, int $t): ?array
{
    return introColor($p, $t);
}

function outroColorOf(array $p, int $t): ?array
{
    return outroState($p, $t)[1];
}

function outroPosOf(array $p, int $t): array
{
    return outroState($p, $t)[0];
}

// ================================================================ BUILD

$write = !in_array('--dump', $argv, true);

[, $ink] = buildInk();
$parts = makeParticles($ink, SEED);

$static = lkEncode(lkHalfBlock(staticPixels($ink)), 'tc');
[$sw, $sh] = lkVerify($static, SLUG . '/static');
lkCheckDepth($static, 'tc', SLUG . '/static');

// Single-frame inspection: php generator --frame=N [--outro] > scratch.ansi
foreach ($argv as $arg) {
    if (preg_match('/^--frame=(\d+)$/', $arg, $m)) {
        $t = (int) $m[1];
        $outro = in_array('--outro', $argv, true);
        echo paintFrame(
            $parts,
            $outro ? 'outroPosOf' : 'introPos',
            $outro ? 'outroColorOf' : 'introColorOf',
            $t,
        );
        exit(0);
    }
}

if (!$write) {
    echo $static;
    fwrite(STDERR, "static {$sw}x{$sh}, " . count($ink) . " particles\n");
    exit(0);
}

$blankCells = [];
for ($y = 0; $y < PH; $y++) {
    $blankCells[$y] = array_fill(0, PW, null);
}
$blank = lkEncode(lkHalfBlock($blankCells), 'tc');

// --- intro -------------------------------------------------------------
$frames = [];
for ($t = 0; $t < N_INTRO - 2; $t++) {
    $frames[] = paintFrame($parts, 'introPos', 'introColorOf', $t);
}
$animIntro = lkAnim($frames, $static);
lkVerify(lkLastFrame($animIntro), SLUG . '/intro');
lkCheckDepth($animIntro, 'tc', SLUG . '/intro');
file_put_contents(__DIR__ . '/logo-' . SLUG . '-anim-tc.ansi', $animIntro);

// --- outro --------------------------------------------------------------
$framesO = [];
for ($t = 0; $t < N_OUTRO - 2; $t++) {
    $framesO[] = paintFrame($parts, 'outroPosOf', 'outroColorOf', $t);
}
$animOutro = lkAnim($framesO, $blank);
lkVerify(lkLastFrame($animOutro), SLUG . '/outro');
lkCheckDepth($animOutro, 'tc', SLUG . '/outro');
file_put_contents(__DIR__ . '/logo-' . SLUG . '-outro-tc.ansi', $animOutro);

// --- static -------------------------------------------------------------
file_put_contents(__DIR__ . '/logo-' . SLUG . '-tc.ansi', $static);

$nfI = substr_count($animIntro, "\e[" . $sh . 'A' . "\r");
$nfO = substr_count($animOutro, "\e[" . $sh . 'A' . "\r");
printf(
    "static  %dx%d  %d bytes\nintro   %dx%d  %d frames  %d bytes\noutro   %dx%d  %d frames  %d bytes\nparticles %d\n",
    $sw, $sh, strlen($static),
    $sw, $sh, count($frames) + 1, strlen($animIntro),
    $sw, $sh, count($framesO) + 1, strlen($animOutro),
    count($parts),
);

<?php

declare(strict_types=1);

/**
 * generate-film-strip-reel-in — "Phlix" as a 35mm film strip.
 *
 * One letter per film frame: the wordmark "Phlix" sits inside five 35mm
 * frames (P h l i x), each letter a dark silhouette on a glowing cream
 * frame window — a strip lit from behind by the projector. Perforated
 * sprocket bands run above and below; warm amber light spills off the
 * strip onto the "shelf" it rests on.
 *
 * The animation is a reel-in: the strip whips past from the right with
 * horizontal motion-blur ghosts, rattles in the gate, overshoots, SNAPS
 * back with a projector flash, settles, and a warm light leak travels
 * the length of the strip before it locks into the static logo.
 *
 * Usage:
 *   php generate-film-strip-reel-in.php            preview static + verify
 *   php generate-film-strip-reel-in.php --write    write the .ansi + .png files
 *   php generate-film-strip-reel-in.php --anim     play the animation
 *   php generate-film-strip-reel-in.php --roles    debug: dump geometry map
 *
 * Palette: film-stock blacks, sepia/cream frame windows, warm amber accent.
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'film-strip-reel-in';
const TEXT = 'Phlix';

/** 5x7 mask; glyph rows 0..1 = ascender band, rows 2..6 = x-height band. */
const FONT = [
    'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
    'h' => ['#....', '#....', '#.##.', '##..#', '#...#', '#...#', '#...#'],
    'l' => ['..#..', '..#..', '..#..', '..#..', '..#..', '..#..', '.###.'],
    'i' => ['.....', '.....', '..#..', '.....', '..#..', '..#..', '..#..'],
    'x' => ['.....', '.....', '#...#', '.#.#.', '..#..', '.#.#.', '#...#'],
];

/** Frame window column ranges (inclusive); dividers sit between windows. */
const WINDOWS = [[8, 18], [20, 30], [32, 42], [44, 54], [56, 66]];
const DIVIDERS = [7, 19, 31, 43, 55, 67];
const PARTIAL_L = [2, 6];          // left partial window (strip continues off-screen)
const PARTIAL_R = [68, 71];        // right partial window
const STRIP_X0 = 1;                // first strip column
const STRIP_X1 = 72;               // last strip column
const W = 75;                      // canvas width in columns

// Canvas pixel rows (2 px per cell row, half-block pipeline):
const PY_GLOW_T = 0;               // amber haze above the strip
const PY_EDGE_T = 1;               // strip top edge (catch-light)
const PY_BAND_T = [2, 3];          // top perforation band
const PY_BORDER_T = [4, 5];        // black inter-frame border (top)
const PY_WIN0 = 6;                 // window rows 6..19 = 7 glyph rows x 2 px
const PY_WIN1 = 19;
const PY_BORDER_B = [20, 21];      // black inter-frame border (bottom)
const PY_BAND_B = [22, 23];        // bottom perforation band
const PY_EDGE_B = 24;              // strip bottom edge
const PY_GLOW_B = 25;              // light spill below the strip
const PY_SHADOW = 26;              // outer contact shadow (cell row 13)
const H_PX = 28;                   // canvas rows -> 14 cell rows (height limit)

const PERIOD = 6;                  // perforation pitch (px)
const PERF_C0 = 5;                 // first perforation centre

// Palette — restrained film stock.
const INK        = '#171009';      // letter silhouette
const INK_SOFT   = '#2C1F11';
const BASE_BLACK = '#070605';      // inter-frame border / edge line
const STOCK      = '#1A1611';      // strip body (charcoal film base)
const STOCK_HI   = '#262018';      // top edge catch-light
const STOCK_LO   = '#120F0B';      // bottom edge
const HOLE       = '#C4B592';      // perforation ivory (light through)
const HOLE_TOP   = '#D8CAA6';
const CREAM_MID  = '#EDD9A6';      // frame window mid tone
const VIGN       = '#B4894B';      // window edge vignette
const AMBER      = '#FFA63A';      // Phlix brand accent
const AMBER_HOT  = '#FFC46B';
const LEAK       = '#FF9636';      // light leak
const FOG        = '#151009';      // motion-blur decay target
const SHADOW_C   = '#0C0A08';
const WIN_STOPS  = ['#F6EAC9', '#F1E0B8', '#EFDBAC', '#ECD4A2', '#EFCE97']; // per-frame warmth

const FS_ANIM_FRAMES = 44;         // + final static frame

const DESCRIPTION = 'Phlix on a 35mm film strip: one letter per frame, dark silhouettes on glowing cream windows, sprocket-perforated edges, warm amber projector light. The strip whips in with motion blur, snaps and settles.';
const TAGS = ['film-strip', '35mm', 'perforations', 'reel-in', 'motion-blur', 'half-block', 'sepia-cream', 'amber-glow', 'animated'];

// ================================================================ GEOMETRY HELPERS

function fsWindowAt(int $x): ?int
{
    foreach (WINDOWS as $k => [$a, $b]) {
        if ($x >= $a && $x <= $b) {
            return $k;
        }
    }
    return null;
}

function fsIsDivider(int $x): bool
{
    return in_array($x, DIVIDERS, true);
}

function fsInStrip(int $x): bool
{
    return $x >= STRIP_X0 && $x <= STRIP_X1;
}

/** Is pixel column x inside a perforation hole? */
function fsIsPerf(int $x): bool
{
    if ($x < STRIP_X0 + 1 || $x > STRIP_X1 - 1) {
        return false;
    }
    $d = ($x - PERF_C0) % PERIOD;
    if ($d > intdiv(PERIOD, 2)) {
        $d -= PERIOD;
    }
    return in_array($d, [-1, 0, 1], true);
}

/** Ink at glyph (row $gy, local col $gxc) of letter frame $k. */
function fsGlyph(int $k, int $gy, int $gxc): bool
{
    if ($gxc < 0 || $gxc > 4 || $gy < 0 || $gy > 6) {
        return false;
    }
    $ch = substr(TEXT, $k, 1);
    $row = FONT[$ch][$gy] ?? '.....';
    return ($row[$gxc] ?? '.') === '#';
}

/** Light "heat" per column: bright under frame windows, dim across stock. */
function fsHeat(int $x): float
{
    $k = fsWindowAt($x);
    if ($k !== null) {
        $cx = (WINDOWS[$k][0] + WINDOWS[$k][1]) / 2;
        $d = abs($x - $cx) / ((WINDOWS[$k][1] - WINDOWS[$k][0]) / 2 + 1);
        return 0.72 + 0.28 * max(0.0, 1 - $d * $d);
    }
    if (fsIsDivider($x)) {
        return 0.18;
    }
    if ($x >= PARTIAL_L[0] && $x <= PARTIAL_R[1]) {
        return 0.30;
    }
    return 0.0;
}

/** Frame window colour at (relX 0..10, row 0..13) of letter frame k. */
function fsWindowTone(int $k, int $rx, int $r): array
{
    $top = lkHex(WIN_STOPS[$k]);
    $bot = lkMix(lkHex(CREAM_MID), $top, 0.25);
    $c = lkMix($top, $bot, $r / 13);
    $ex = min($rx, 10 - $rx);                       // vignette at window edges
    if ($ex === 0) {
        $c = lkMix($c, lkHex(VIGN), 0.34);
    } elseif ($ex === 1) {
        $c = lkMix($c, lkHex(VIGN), 0.13);
    }
    if ($r === 0) {
        $c = lkShade($c, 0.10);                     // celluloid catch-light
    }
    if ($r === 13) {
        $c = lkScale($c, 0.90);
    }
    $g = (lkNoise($rx + 17 * $k, $r, 7) - 0.5) * 0.06;   // film grain
    return $g > 0 ? lkShade($c, $g) : lkScale($c, 1 + $g);
}

// ================================================================ STRIP DESIGN

/**
 * The strip as a canvas-row-major pixel grid (rows 0..PY_GLOW_B, W cols),
 * rgb|null. Animation paints shifted/jittered copies of this design.
 *
 * @return list<list<?array>>
 */
function fsStripDesign(): array
{
    $px = array_fill(0, PY_GLOW_B + 1, array_fill(0, W, null));

    for ($x = 0; $x < W; $x++) {
        if (!fsInStrip($x)) {
            continue;
        }
        $heat = fsHeat($x);
        $glow = $heat > 0 ? lkMix(lkHex(FOG), lkHex(AMBER), 0.10 + 0.16 * $heat) : null;
        $spill = $heat > 0 ? lkMix(lkHex('#050403'), lkHex(AMBER_HOT), 0.14 + 0.26 * $heat) : lkHex(SHADOW_C);

        $px[PY_GLOW_T][$x] = $glow;
        $px[PY_EDGE_T][$x] = lkHex(STOCK_HI);
        $px[PY_EDGE_B][$x] = lkHex(STOCK_LO);
        $px[PY_GLOW_B][$x] = $spill;

        $hole = fsIsPerf($x);
        foreach ([PY_BAND_T, PY_BAND_B] as $band) {
            $px[$band[0]][$x] = $hole ? lkHex(HOLE) : lkHex(STOCK);
            $px[$band[1]][$x] = $hole ? lkHex(HOLE_TOP) : lkHex(STOCK);
        }

        foreach ([PY_BORDER_T, PY_BORDER_B] as $bb) {
            $px[$bb[0]][$x] = lkHex(BASE_BLACK);
            $px[$bb[1]][$x] = lkHex(BASE_BLACK);
        }

        $k = fsWindowAt($x);
        $isPart = ($x >= PARTIAL_L[0] && $x <= PARTIAL_L[1]) || ($x >= PARTIAL_R[0] && $x <= PARTIAL_R[1]);
        for ($r = 0; $r <= PY_WIN1 - PY_WIN0; $r++) {
            $py = PY_WIN0 + $r;
            if ($k !== null) {
                $gxc = $x - (WINDOWS[$k][0] + 3);       // glyph local column
                $gy = intdiv($r, 2);
                if (fsGlyph($k, $gy, $gxc)) {
                    // constant ink per GLYPH row (not per pixel row) so the
                    // two 1-px halves pair into solid █ cells
                    $px[$py][$x] = lkMix(lkHex(INK), lkHex(INK_SOFT), $gy / 6);
                } else {
                    $c = fsWindowTone($k, $x - WINDOWS[$k][0], $r);
                    // soft edge so silhouettes don't look aliased
                    if (fsGlyph($k, $gy, $gxc - 1) || fsGlyph($k, $gy, $gxc + 1)
                        || fsGlyph($k, $gy - 1, $gxc) || fsGlyph($k, $gy + 1, $gxc)) {
                        $c = lkScale($c, 0.93);
                    }
                    $px[$py][$x] = $c;
                }
            } elseif ($isPart) {
                $tone = 0.50 + 0.08 * ($x % 3);
                $dim = $x <= PARTIAL_L[1] ? 0.30 : 0.42;   // left streaky, right unexposed
                $px[$py][$x] = lkScale(lkMix(lkHex(STOCK), lkHex(CREAM_MID), $dim), $tone);
            } else {
                $px[$py][$x] = fsIsDivider($x) ? lkHex(BASE_BLACK) : lkHex(STOCK);
            }
        }
    }
    return $px;
}

// ================================================================ CANVAS PAINTING

/**
 * Paint the strip design onto the 28-row canvas shifted $sx px right,
 * $dy px down; brightness $m; mixed toward motion fog by $fade.
 */
function fsPaint(array &$canvas, array $design, int $sx, int $dy, float $m, float $fade): void
{
    $fog = lkHex(FOG);
    foreach ($design as $dr => $row) {
        $cy = (int) $dr + $dy;
        if ($cy < 0 || $cy >= H_PX) {
            continue;
        }
        foreach ($row as $dx => $c) {
            if ($c === null) {
                continue;
            }
            $cx = (int) $dx + $sx;
            if ($cx < 0 || $cx >= W) {
                continue;
            }
            $p = $c;
            if ($m > 1.0) {
                $p = lkShade($p, min(0.6, $m - 1));
            } elseif ($m < 1.0) {
                $p = lkScale($p, $m);
            }
            if ($fade > 0.0) {
                $p = lkMix($p, $fog, min(0.95, $fade));
            }
            $canvas[$cy][$cx] = $p;
        }
    }
}

/** Warm light-leak band centred at canvas column $hx. */
function fsLeak(array &$canvas, float $hx, float $power): void
{
    $lk = lkHex(LEAK);
    for ($x = max(0, (int) $hx - 5); $x <= min(W - 1, (int) $hx + 5); $x++) {
        $d = abs($x - $hx);
        if ($d > 5) {
            continue;
        }
        $a = $power * (1 - $d / 5) ** 2;
        if ($a <= 0.01) {
            continue;
        }
        for ($y = 0; $y < H_PX; $y++) {
            $c = $canvas[$y][$x] ?? null;
            if ($c === null) {
                continue;
            }
            $canvas[$y][$x] = lkMix($c, $lk, min(0.85, $a * (1.4 - $c[1] / 700)));
        }
    }
}

/** Film-gate rattle: tiny vertical offset while the strip moves fast. */
function fsJitter(float $t, float $trail): int
{
    if ($trail < 0.35) {
        return 0;
    }
    return (int) floor(lkNoise((int) round($t * 2), 3, 21) * 3) - 1;
}

// ================================================================ KINEMATICS

/** Master x-position (px right of rest): whip in +46, brake, overshoot -15, rebound +5. */
function fsWhipShift(float $t): float
{
    if ($t <= 17) {
        $p = $t / 17;
        $ss = $p * $p * (3 - 2 * $p);                 // smoothstep: long visible travel
        return 46 - 61 * $ss;
    }
    $p = min(1.0, ($t - 17) / 6);                     // overshoot -15, rebound to +5
    return -15 + 20 * (1 - (1 - $p) ** 2);
}

/** @return array{shift:int,trail:float,vsign:int,bright:float,dy:int,leak:?array} */
function fsState(float $t): array
{
    $shift = 0;
    $trail = 0.0;
    $vsign = -1;                                    // motion direction (ghosts trail behind)
    if ($t < 20) {
        $now = fsWhipShift($t);
        $prev = fsWhipShift(max(0, $t - 1));
        $shift = (int) round($now);
        $vsign = $now >= $prev ? 1 : -1;
        $trail = min(1.0, abs($now - $prev) / 7);
    } elseif ($t < 27) {
        $p = ($t - 20) / 7;
        $shift = (int) round(5 * cos(2.1 * M_PI * $p) * (1 - $p) ** 1.6);
        $trail = 0.25 * (1 - $p);
    }
    $bright = 1.0;
    if ($t >= 19.6 && $t <= 21.4) {
        $bright = 1.22 - 0.06 * ($t - 19.6);          // snap flash
    }
    if ($t >= 18 && $t < 31) {
        $bright *= 1 + (1 - ($t - 18) / 13) * 0.10 * (lkNoise((int) $t, 11, 5) - 0.5) * 2;  // projector flicker
    }
    $leak = null;
    if ($t >= 30 && $t <= 39) {
        $p = ($t - 30) / 9;
        $leak = [4.0 + (W - 8) * $p, sin(M_PI * min(1.0, $p)) * 0.9];
    }
    return ['shift' => $shift, 'trail' => $trail, 'vsign' => $vsign, 'bright' => $bright, 'dy' => fsJitter($t, $trail), 'leak' => $leak];
}

// ================================================================ FRAME BUILD

/** Contact shadow + glow rows under the strip at its current shift. */
function fsFootprint(array &$canvas, int $shift): void
{
    for ($x = 0; $x < W; $x++) {
        $dcol = $x - $shift;
        if (!fsInStrip($dcol)) {
            continue;
        }
        $h = fsHeat($dcol);
        if ($h > 0) {
            $canvas[PY_SHADOW][$x] = lkMix(lkHex(SHADOW_C), [0, 0, 0], 0.4 + 0.5 * (1 - $h));
        }
    }
}

function fsCells(float $t = -1.0): array
{
    $design = fsStripDesign();
    $canvas = array_fill(0, H_PX, array_fill(0, W, null));
    if ($t < 0) {
        fsPaint($canvas, $design, 0, 0, 1.0, 0.0);
        fsFootprint($canvas, 0);
    } else {
        $st = fsState($t);
        if ($st['trail'] > 0.18) {                     // motion-blur ghosts: copies trailing the direction of travel
            foreach ([[14, 0.26], [9, 0.40], [4, 0.55]] as [$gap, $alpha]) {
                $fade = 1 - max(0.0, min(1.0, $st['trail'] * $alpha * 1.6));
                fsPaint($canvas, $design, $st['shift'] - (int) round($gap * max(0.5, $st['trail'])) * $st['vsign'], $st['dy'], 1.0, $fade);
            }
        }
        fsPaint($canvas, $design, $st['shift'], $st['dy'], $st['bright'], 0.0);
        fsFootprint($canvas, $st['shift']);
        if ($st['leak'] !== null) {
            fsLeak($canvas, $st['leak'][0] + $st['shift'], $st['leak'][1]);
        }
    }
    return lkHalfBlock($canvas);
}

// ================================================================ CLI

function fsRoles(): void
{
    fwrite(STDOUT, "cols 0.." . (W - 1) . ", strip " . STRIP_X0 . ".." . STRIP_X1 . "\n");
    $win = $perf = '';
    for ($x = 0; $x < W; $x++) {
        $win .= match (true) {
            !fsInStrip($x) => '.',
            fsWindowAt($x) !== null => 'W',
            fsIsDivider($x) => '|',
            $x >= PARTIAL_L[0] && $x <= PARTIAL_L[1] => 'p',
            $x >= PARTIAL_R[0] && $x <= PARTIAL_R[1] => 'q',
            default => 's',
        };
        $perf .= fsIsPerf($x) ? 'o' : (($x < STRIP_X0 || $x > STRIP_X1) ? '.' : '-');
    }
    fwrite(STDOUT, "win   $win\nperf  $perf\n");
    foreach (str_split(TEXT) as $k => $ch) {
        fwrite(STDOUT, "glyph $ch:\n");
        for ($gy = 0; $gy < 7; $gy++) {
            $line = '';
            for ($gc = 0; $gc < 5; $gc++) {
                $line .= fsGlyph($k, $gy, $gc) ? '#' : '.';
            }
            fwrite(STDOUT, "  $line\n");
        }
    }
    for ($t = 0; $t < FS_ANIM_FRAMES; $t++) {
        $st = fsState((float) $t);
        fwrite(STDERR, sprintf("t=%2d shift=%3d trail=%.2f bright=%.3f dy=%d leak=%s\n",
            $t, $st['shift'], $st['trail'], $st['bright'], $st['dy'],
            $st['leak'] === null ? '-' : sprintf('%.1f/%.2f', $st['leak'][0], $st['leak'][1])));
    }
}

$write = in_array('--write', $argv, true);
$play = in_array('--anim', $argv, true);

// debug aid: dump one raw animation frame to stdout, e.g. --frame=6
foreach ($argv as $arg) {
    if (preg_match('/^--frame=(\d+)$/', $arg, $m)) {
        echo lkEncode(fsCells((float) (int) $m[1]), 'tc');
        exit(0);
    }
}

if (in_array('--roles', $argv, true)) {
    fsRoles();
    exit(0);
}

$dir = __DIR__;
$static = lkEncode(fsCells(), 'tc');
[$w, $h] = lkVerify($static, 'static/tc');
lkCheckDepth($static, 'tc', 'static/tc');
if ($w > 80 || $h > 14) {
    throw new RuntimeException("bounds: {$w}x{$h} exceeds 80x14");
}
fwrite(STDERR, "[tc static] {$w}x{$h} ok\n");

$frames = [];
for ($t = 0; $t < FS_ANIM_FRAMES; $t++) {
    $frames[] = lkEncode(fsCells((float) $t), 'tc');
}
$anim = lkAnim($frames, $static);            // standard "\e[<H>A\r" joiner -> logo-play compatible
[$aw, $ah] = lkVerify(lkLastFrame($anim), 'anim-last/tc');
lkCheckDepth($anim, 'tc', 'anim/tc');
fwrite(STDERR, '[tc anim] ' . count($frames) . " frames, last {$aw}x{$ah} ok\n");

if ($write) {
    $sf = "$dir/logo-" . SLUG . "-tc.ansi";
    $af = "$dir/logo-" . SLUG . "-anim-tc.ansi";
    file_put_contents($sf, $static);
    file_put_contents($af, $anim);
    fwrite(STDERR, "  wrote $sf\n  wrote $af\n");
    passthru('php ' . escapeshellarg("$dir/logo-preview.php") . ' ' . escapeshellarg($sf) . ' ' . escapeshellarg("$dir/logo-" . SLUG . "-tc.png") . " '#0D0C0A' 8 16");
}

if ($play) {
    $tmp = "$dir/.anim-play.tmp.ansi";
    file_put_contents($tmp, $anim);
    passthru('php ' . escapeshellarg("$dir/logo-play.php") . ' ' . escapeshellarg($tmp) . ' 100');
    unlink($tmp);
    echo "\n";
} elseif (!$write) {
    echo $static, "\n";
}

<?php

declare(strict_types=1);

/**
 * generate-iris-aperture — concept #18 for the phlix-console-client logo wave.
 *
 * A camera iris: the screen opens on six overlapping gunmetal blades closed
 * to a tiny hexagonal pinhole (a spark of the letter P glows amber through
 * it). Frame by frame the blades rotate and retract outward; the hexagonal
 * opening morphs toward a circle and a bright thin rim of light catches the
 * blade edges, revealing the warm amber Phlix wordmark underneath. By the
 * last motion frames the blades have fully retracted off the corners and are
 * gone, leaving the clean logo.
 *
 * Geometry: everything is computed per-cell in a square-pixel grid (1 pixel
 * = 1 column × half a row; half-blocking recombines pairs). A circle in this
 * space reads round on screen — the blade boundary is a regular-hexagon
 * apothem formula blended toward a circle as the aperture grows, rotated by
 * the iris motion. Blade body shading (leading-edge bright, overlap shadow
 * lines, radial vignette to black at the frame edges) lives in bladePx().
 *
 * Usage:
 *   php generate-iris-aperture.php            static tc to stdout (+ verify)
 *   php generate-iris-aperture.php --write    write logo-iris-aperture-anim-tc.ansi
 *                                             + logo-iris-aperture-tc.ansi
 * View:  php logo-play.php logo-iris-aperture-anim-tc.ansi 80
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'iris-aperture';
const TEXT = 'Phlix';

const CELL_W = 52;            // columns in the output
const CELL_H = 12;            // rows (≤ 14)
const PIX_W = CELL_W;         // square-pixel canvas: 1 px = 1 col
const PIX_H = CELL_H * 2;     //   … × 2 rows = 2 px tall per cell

const FRAMES = 60;            // motion frames (final static appended by lkAnim)
const HOLD = 3;               // frames spent closed before the iris breaks
const R0 = 1.6;               // pinhole radius (closed iris)
const R_MID = 15.0;           // opening radius where the last full ring clears
const R1 = 40.0;              // opening radius past the farthest corner
const P_MID = 0.62;           // motion progress at which R_MID is reached
const RIM_W = 2.2;            // px width of the light-catching rim
const BLADE_BAND = 11.0;      // px trail of blade metal behind each edge
const SECTOR = M_PI / 3;      // 6 blades

/** Pixel font, 7 px tall → ~3.5 terminal rows after half-blocking. */
const FONT = [
    'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
    'h' => ['#....', '#....', '####.', '#...#', '#...#', '#...#', '#...#'],
    'l' => ['.##', '..#', '..#', '..#', '..#', '..#', '.##'],
    'i' => ['.#.', '...', '.#.', '.#.', '.#.', '.#.', '.##'],
    'x' => ['#...#', '#...#', '.#.#.', '..#..', '.#.#.', '#...#', '#...#'],
];

const GRADIENT = ['#FFDF9E', '#FFB25A', '#E08028', '#D96E1E'];  // amber, top→bottom
const SHADOW = '#4A2408';
const DESCRIPTION = 'Camera iris opening: six rotating gunmetal blades retract from a hexagonal pinhole (glowing with a spark of the letter P), morphing the aperture toward a circle while a bright rim of light catches every blade edge, revealing the warm amber Phlix wordmark.';
const TAGS = ['iris', 'camera', 'aperture', 'metal-blades', 'animated', 'reveal', 'amber'];

// Layout of the wordmark inside the pixel grid (computed once).
[$MASK, $RANGES] = lkLayout(FONT, TEXT, letterGap: 1, wordGap: 3, margin: 0);
const INK_Y = 8;                                        // top pixel row of the word
function inkX(): int { return intdiv(PIX_W - strlen($GLOBALS['MASK'][0]), 2); }

// Iris pivot: on the stem of the P, inside the bowl.
function iris(): array { return [inkX() + 0.5, INK_Y + 2]; }

// ================================================================ MATH HELPERS

function clamp01(float $v): float
{
    return max(0.0, min(1.0, $v));
}

/**
 * Perceptual timeline: most of the run is spent inside the wordmark
 * (R0 -> R_MID, where the ring is still crossing the letters), then the
 * last blades slide off the frame corner at a steadier crawl.
 */
function openRadius(float $p): float
{
    if ($p <= P_MID) {
        $t = $p / P_MID;
        $s = 3 * $t * $t - 2 * $t * $t * $t;      // smoothstep in/out
        $s = 0.55 * $s + 0.45 * $t;               // keep the break alive
        return R0 + (R_MID - R0) * $s;
    }
    $t = ($p - P_MID) / (1 - P_MID);
    return R_MID + (R1 - R_MID) * ($t * 0.7 + $t * $t * 0.3);
}

/** '#' ink check on the wordmark mask. */
function inkOn(int $x, int $y): bool
{
    $rx = $x - inkX();
    $ry = $y - INK_Y;
    if ($rx < 0 || $ry < 0 || $ry >= count($GLOBALS['MASK'])) {
        return false;
    }
    return ($GLOBALS['MASK'][$ry][$rx] ?? '.') === '#';
}

/** Amber wordmark colour with a lit top edge; null for non-ink. */
function logoPx(int $x, int $y): ?array
{
    if (!inkOn($x, $y)) {
        return null;
    }
    $t = ($y - INK_Y) / (count($GLOBALS['MASK']) - 1);
    $c = lkGradient(GRADIENT, $t);
    if ($y === INK_Y) {
        $c = lkMix($c, [255, 255, 255], 0.45);
    }
    return $c;
}

/** Faint warm bloom around ink, seen through the opening. */
function haloPx(int $x, int $y): ?array
{
    for ($dy = -1; $dy <= 1; $dy++) {
        for ($dx = -1; $dx <= 1; $dx++) {
            if (($dx || $dy) && inkOn($x + $dx, $y + $dy)) {
                return [40, 26, 11];
            }
        }
    }
    return null;
}

/**
 * Gunmetal blade body. $u = position within the blade sector (0 leading
 * edge → 1 trailing), $y = pixel row, $depth = fraction of the blade width
 * travelled from the aperture edge (0 edge → 1 frame edge).
 */
function bladePx(float $u, int $x, int $y, float $depth): array
{
    $lit = [106, 118, 136];
    $dark = [26, 30, 38];
    $v = 0.40 + 0.50 * (1 - $u) + 0.10 * (1 - $y / (PIX_H - 1));
    $c = lkMix($dark, $lit, clamp01($v));
    // specular streak across each plate
    $streak = (int) round(34 * max(0.0, sin(M_PI * $u) - 0.5));
    $c = [$c[0] + $streak, $c[1] + $streak, $c[2] + $streak + 2];
    // overlap shadow: the trailing lip of each plate over the next
    if ($u > 0.90 && $depth < 0.55) {
        $c = lkScale($c, 0.42);
    }
    return $c;
}

// ================================================================ FRAME BUILD

/**
 * One motion frame as a pixel grid. $p = 0..1 iris opening progress.
 * @return list<list<?array>>
 */
function aperturePixels(float $p): array
{
    [$cx, $cy] = iris();
    $ropen = openRadius($p);
    $morph = clamp01(($ropen - 2.5) / 7.0);      // hexagon → circle as it grows
    $rot = 1.15 * min(1.0, $p / 0.8);            // blades rotate on the way out

    $px = array_fill(0, PIX_H, array_fill(0, PIX_W, null));
    for ($y = 0; $y < PIX_H; $y++) {
        for ($x = 0; $x < PIX_W; $x++) {
            $dx = $x - $cx;
            $dy = $y - $cy;
            $r = sqrt($dx * $dx + $dy * $dy);
            $th = atan2($dy, $dx);

            // boundary: regular hexagon (apothem = $ropen) blended to circle
            $u = (fmod(fmod($th - $rot, 2 * M_PI) + 2 * M_PI, SECTOR)) / SECTOR;
            $bHex = $ropen * cos(SECTOR / 2) / cos(($u - 0.5) * SECTOR);
            $b = (1 - $morph) * $bHex + $morph * $ropen;

            if ($r < $b) {
                // through the aperture: the wordmark underneath
                $px[$y][$x] = logoPx($x, $y) ?? haloPx($x, $y);
                continue;
            }
            // blade metal: a band that trails the aperture edge as it retracts
            $e = $r - $b;
            if ($e > BLADE_BAND) {
                continue;                                   // beyond the plate
            }
            $depth = $e / BLADE_BAND;
            $alpha = $e < 3.5 ? 1.0 : (1 - ($e - 3.5) / (BLADE_BAND - 3.5)) ** 1.15;
            if ($alpha < 0.08) {
                continue;
            }
            $body = bladePx($u, $x, $y, $depth);
            $c = lkScale($body, $alpha);
            if ($e < RIM_W) {
                // bright thin ring of light catching the blade edge
                $glow = lkMix([242, 246, 252], [255, 176, 86], 0.30);
                $c = lkMix($c, $glow, (1 - $e / RIM_W) ** 0.55);
            }
            $px[$y][$x] = $c;
        }
    }
    return $px;
}

/** Final frame: clean wordmark on black, blades fully retracted. */
function staticPixels(): array
{
    $px = array_fill(0, PIX_H, array_fill(0, PIX_W, null));
    for ($y = 0; $y < PIX_H; $y++) {
        for ($x = 0; $x < PIX_W; $x++) {
            if ($c = logoPx($x, $y)) {
                $px[$y][$x] = $c;
            } elseif ($c = haloPx($x, $y)) {
                $px[$y][$x] = $c;
            } elseif (inkOn($x - 1, $y - 1)) {
                $px[$y][$x] = lkHex(SHADOW);                  // 1px drop shadow
            }
        }
    }
    return $px;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dir = __DIR__;

// Debug aid: `--frame=N` renders motion frame N (0..FRAMES-1) to stdout.
foreach ($argv as $arg) {
    if (preg_match('/^--frame=(\d+)$/', $arg, $m) && (int) $m[1] < FRAMES) {
        $f = (int) $m[1];
        $p = $f < HOLD ? 0.0 : ($f - HOLD) / (FRAMES - 1 - HOLD);
        echo lkEncode(lkHalfBlock(aperturePixels($p)), 'tc');
        exit(0);
    }
}

$static = lkEncode(lkHalfBlock(staticPixels()), 'tc');
[$w, $h] = lkVerify($static, SLUG);
lkCheckDepth($static, 'tc', SLUG);

if (!$write) {
    echo $static;
    fwrite(STDERR, "[tc] static {$w}x{$h} ok\n");
    exit(0);
}

$frames = [];
for ($f = 0; $f < FRAMES; $f++) {
    $p = $f < HOLD ? 0.0 : ($f - HOLD) / (FRAMES - 1 - HOLD);
    $frame = lkEncode(lkHalfBlock(aperturePixels($p)), 'tc');
    lkVerify($frame, SLUG . "/f$f");
    lkCheckDepth($frame, 'tc', SLUG . "/f$f");
    $frames[] = $frame;
}
$anim = lkAnim($frames, $static);
lkVerify(lkLastFrame($anim), SLUG . '/anim-tail');
lkCheckDepth($anim, 'tc', SLUG . '/anim');

file_put_contents("$dir/logo-" . SLUG . "-tc.ansi", $static);
file_put_contents("$dir/logo-" . SLUG . "-anim-tc.ansi", $anim);
fwrite(STDERR, "[tc] wrote logo-" . SLUG . "-tc.ansi ($w x $h, " . strlen($static) . "B)\n");
fwrite(STDERR, "[tc] wrote logo-" . SLUG . "-anim-tc.ansi (" . (count($frames) + 1) . " frames, " . strlen($anim) . "B)\n");

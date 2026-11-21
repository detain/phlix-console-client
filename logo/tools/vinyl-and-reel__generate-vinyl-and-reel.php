<?php

declare(strict_types=1);

/**
 * generate-vinyl-and-reel — "Phlix" flanked by a vinyl record and a film reel.
 *
 * Concept #8 (Vinyl & Reel): a black grooved record with a warm amber label
 * rolls in from the left, a perforated film reel rolls in from the right —
 * both spinning as they travel — and dock beside the "Phlix" wordmark:
 * music and movies meeting in the middle. An amber glint sweeps the wordmark
 * as they land; the settle pose idles with a slow rotation while a film tail
 * threads out off the reel's upper shoulder.
 *
 * Canvas: 78 cols × 14 rows (half-block idiom → a 78×28 sub-pixel grid, so
 * the 24px discs read as true circles). Restrained palette: vinyl blacks
 * with groove-ring shading under a fixed specular crescent (orbiting label
 * mark and dust motes sell the spin), warm amber label/brand accent,
 * gunmetal reel with quantised film-slot rim and punched spoke windows,
 * cream-to-amber wordmark over a brown drop shadow.
 *
 * Usage:
 *   php generate-vinyl-and-reel.php              preview static tc to stdout (+ verify)
 *   php generate-vinyl-and-reel.php --write      write the two .ansi deliverables
 *   php generate-vinyl-and-reel.php --peek F[,F…]  dump specific frames to peek-F.ansi
 *
 * All writes stay inside this directory.
 */

require __DIR__ . '/logo-kit.php';

const SLUG   = 'vinyl-and-reel';
const TEXT   = 'Phlix';
const CW     = 78;             // canvas columns
const CH     = 14;             // canvas terminal rows
const SH     = CH * 2;         // sub-pixel rows
const CY     = 13.5;           // shared disc center row (sub-pixels)
const R_DISC = 12.0;           // disc radius (1 col ≈ 1 sub-row on screen)
const F_ANIM = 40;             // last animation frame index (0-based)

const VINYL_X = 11;            // settled disc centers (ink cols 0..23 / 54..77)
const REEL_X  = 65;
const WORD_Y0 = 8;             // wordmark band: sub-rows 8..19 (cap height)
const WORD_X0 = 26;            // ink cols 26..51, shadow to 52

/** Pixel masks: 12 sub-rows tall, 2px stems; cap/ascenders r0..11, x-height r5..11. */
const FONT = [
    'P' => ['.####.', '##..##', '##..##', '##..##', '.####.', '##....', '##....', '##....', '##....', '##....', '##....', '##....'],
    'h' => ['##....', '##....', '##....', '##....', '##.###', '##..##', '##..##', '##..##', '##..##', '##..##', '##..##', '##..##'],
    'l' => ['##', '##', '##', '##', '##', '##', '##', '##', '##', '##', '##', '##'],
    'i' => ['...', '...', '.##', '.##', '...', '.##', '.##', '.##', '.##', '.##', '.##', '.##'],
    'x' => ['.....', '.....', '.....', '.....', '##.##', '#.#.#', '.#.#.', '..#..', '.#.#.', '#.#.#', '##.##', '##.##'],
];

// ---------------------------------------------------------------- palette
const C_PAGE      = '#141519'; // nominal page tone (used for tail perfs only)
const C_VINYL_0   = '#101118'; // groove valley
const C_VINYL_1   = '#272A35'; // groove crest
const C_VINYL_RIM = '#0A0A0E'; // outermost edge punchline
const C_VINYL_3   = '#23252E'; // inner rim band
const C_VINYL_GL  = '#8E9AB2'; // specular crescent
const C_WAX       = '#17181E'; // dead wax between label and grooves
const C_LABEL_IN  = '#E8A94E'; // amber label
const C_LABEL_OUT = '#C77F22';
const C_LBL_EDGE  = '#F5CB78'; // lit label seam
const C_LBL_WEDGE = '#96601A'; // rotating print mark on the label
const C_HOLE_EDGE = '#6B7690'; // window bevel catching light
const C_REEL_FACE  = '#2A2E39';
const C_REEL_FACE2 = '#383E4C';
const C_SPOKE     = '#4E5668';
const C_SPOKE_HI  = '#7C88A1';
const C_RIM_IN    = '#454D5E';  // reel rim metal
const C_SLOT      = '#0D0F15';  // quantised film perforations on the rim
const C_RIM_SHINE = '#97A3BF';
const C_HUB       = '#404858';
const C_HUB_RING  = '#57617A';
const C_WORD_TOP  = '#FFF3DC';  // cream wordmark, lit top …
const C_WORD_BOT  = '#EAC183';  // … fading to amber at the base
const C_SHADOW    = '#221A0E';
const C_TAIL      = '#1E222C';  // threaded film strip
const C_TAIL_EDGE = '#59637A';
const C_TAIL_PERF = '#0B0C10';

const DESCRIPTION = 'Phlix flanked by a spinning vinyl record (grooves, amber label) and a film reel '
    . '(perforated rim, punched spoke windows): both roll in from the sides and dock beside the wordmark.';

// ---------------------------------------------------------------- easing
function clamp01(float $v): float
{
    return max(0.0, min(1.0, $v));
}

function easeOutBack(float $p): float
{
    $c1 = 1.70158;
    $c3 = $c1 + 1.0;
    $q = $p - 1.0;
    return 1.0 + $c3 * $q * $q * $q + $c1 * $q * $q;
}

/** angular difference in degrees, wrapped to (-180,180]. */
function angWrap(float $a): float
{
    $d = fmod($a, 360.0);
    return $d > 180.0 ? $d - 360.0 : ($d < -180.0 ? $d + 360.0 : $d);
}

/** Fixed light from the upper-left: canvas θdeg (y-down) → 0..1 specular weight. */
function spec(float $thDeg): float
{
    return max(0.0, cos(deg2rad(angWrap($thDeg + 135.0))));
}

// ---------------------------------------------------------------- discs
/**
 * Vinyl record pixel. $d radius, $thM material angle (rotates with the disc),
 * $thC canvas angle (fixed light). null paint = punched through.
 */
function vinylPixel(float $d, float $thM, float $thC, array &$px, int $y, int $x): void
{
    if ($d > R_DISC) {
        return;
    }
    $gl = spec($thC);
    if ($d > 11.3) {
        $px[$y][$x] = lkHex(C_VINYL_RIM);
        return;
    }
    if ($d > 10.2) {
        $px[$y][$x] = lkMix(lkHex(C_VINYL_3), lkHex(C_VINYL_GL), $gl * $gl * $gl * 0.9);
        return;
    }
    if ($d > 4.7) {                                    // grooved band
        $ring = (int) round($d) % 2;                   // 1px-wide concentric rings
        $c = lkHex($ring ? C_VINYL_1 : C_VINYL_0);
        $sheen = $gl * $gl;
        if ($sheen > 0.12) {
            $c = lkMix($c, lkHex(C_VINYL_GL), 0.55 * $sheen);
        }
        if (lkNoise((int) round($d), (int) round($thM * 14), 5) > 0.975) {
            $c = lkShade($c, 0.35);                    // dust mote riding the groove
        }
        $px[$y][$x] = $c;
        return;
    }
    if ($d > 3.7) {                                    // dead wax
        $px[$y][$x] = lkHex(C_WAX);
        return;
    }
    if ($d <= 0.85) {                                  // spindle hole
        $px[$y][$x] = null;
        return;
    }
    $c = lkMix(lkHex(C_LABEL_IN), lkHex(C_LABEL_OUT), $d / 3.7);
    if ($d > 3.2) {
        $c = lkMix($c, lkHex(C_LBL_EDGE), 0.5);
    }
    $wa = angWrap(rad2deg($thM));                      // print wedge at material 0°
    if ($wa > -7.0 && $wa < 7.0 && $d > 1.5) {
        $c = lkHex(C_LBL_WEDGE);
    }
    $px[$y][$x] = $c;
}

/** Film reel pixel: rim slots, 5 punched windows with bevels, spokes, hub. */
function reelPixel(float $d, float $thM, float $thC, array &$px, int $y, int $x): void
{
    if ($d > R_DISC) {
        return;
    }
    if ($d <= 0.9) {                                   // spindle hole
        $px[$y][$x] = null;
        return;
    }
    $t = angWrap(rad2deg($thM));
    $gl = spec($thC);
    if ($d > 11.1) {                                   // rim with film perfs
        $slot = ((int) round($t + 729.0)) % 15 < 5;
        $metal = lkMix(lkHex(C_RIM_IN), lkHex(C_RIM_SHINE), $gl * $gl * 0.9 + 0.06);
        $px[$y][$x] = $slot ? lkMix($metal, lkHex(C_SLOT), 0.85) : $metal;
        return;
    }
    if ($d <= 3.5) {                                   // hub
        $c = $d > 3.0 ? lkHex(C_HUB_RING) : lkHex(C_HUB);
        $px[$y][$x] = lkMix($c, lkHex(C_RIM_SHINE), $gl * 0.28);
        return;
    }
    // spoke windows: five circles at radius 7.0, r 2.6 (material frame)
    $mxx = $d * cos($thM);
    $myy = $d * sin($thM);
    for ($k = 0; $k < 5; $k++) {
        $a = deg2rad($k * 72.0);
        $dh = sqrt(($mxx - 7.0 * cos($a)) ** 2 + ($myy - 7.0 * sin($a)) ** 2);
        if ($dh <= 2.6) {
            if ($dh >= 1.9) {
                $px[$y][$x] = lkHex(C_HOLE_EDGE);      // bevel rim all around
            }
            return;                                    // window stays transparent
        }
    }
    // spokes: radial bars at 36° offsets between windows
    $pos = ((int) round($t + 6.6 + 720.0)) % 72;
    if ($pos < 8) {
        $px[$y][$x] = lkMix(lkHex(C_SPOKE), lkHex(C_SPOKE_HI), $gl * $gl);
        return;
    }
    // flange face: subtle brushed noise in the material frame, faint gloss
    $base = lkMix(lkHex(C_REEL_FACE), lkHex(C_REEL_FACE2),
        lkNoise((int) round($mxx * 1.5), (int) round($myy * 1.5), 9));
    $px[$y][$x] = lkMix($base, lkHex(C_RIM_SHINE), $gl * $gl * 0.16);
}

// ---------------------------------------------------------------- wordmark
/** @return list<string> 12-row ink mask of the wordmark */
function wordMask(): array
{
    [$mask] = lkLayout(FONT, TEXT, letterGap: 1, wordGap: 1, margin: 0);
    foreach (FONT as $ch => $rows) {
        $w0 = strlen($rows[0]);
        foreach ($rows as $r) {
            if (strlen($r) !== $w0) {
                throw new RuntimeException("glyph '$ch' ragged mask");
            }
        }
    }
    return $mask;
}

/** @param list<list<?array>> $px */
function drawWordmark(array &$px, ?float $glintX): void
{
    $mask = wordMask();
    $mrows = count($mask);
    $mcols = strlen($mask[0]);
    for ($y = 0; $y < $mrows; $y++) {
        for ($x = 0; $x < $mcols; $x++) {
            if (($mask[$y][$x] ?? '.') !== '#') {
                continue;
            }
            $c = lkGradient([C_WORD_TOP, C_WORD_BOT], $y / ($mrows - 1));
            if ($glintX !== null) {
                $dx = abs($x - $glintX);
                if ($dx < 1.6) {
                    $c = lkMix($c, [255, 250, 235], 0.9 * (1.0 - $dx / 1.6));
                }
            }
            $cy = WORD_Y0 + $y;
            $cx = WORD_X0 + $x;
            if ($cy < SH && $cx < CW) {
                $px[$cy][$cx] = $c;
            }
        }
    }
    // drop shadow down-right, over whatever was underneath
    for ($y = 0; $y < $mrows; $y++) {
        for ($x = 0; $x < $mcols; $x++) {
            if (($mask[$y][$x] ?? '.') !== '#') {
                continue;
            }
            $sy = WORD_Y0 + $y + 1;
            $sx = WORD_X0 + $x + 1;
            if ($sy < SH && $sx < CW && $px[$sy][$sx] === null) {
                $px[$sy][$sx] = lkHex(C_SHADOW);
            }
        }
    }
}

/** Film strip threaded off the reel's upper-right shoulder, growing with $len. */
function drawFilmTail(array &$px, int $cy2, float $len): void
{
    $pts = [[76, 3], [74, 3], [72, 3], [70, 3], [69, 2], [68, 1], [68, 0]];
    $n = (int) ceil(count($pts) * $len);
    for ($i = 0; $i < $n; $i++) {
        [$c, $yr] = $pts[$i];
        for ($y = $yr; $y < $yr + 3 && $y < SH; $y++) {
            for ($xx = $c; $xx < $c + 2 && $xx < CW; $xx++) {
                $px[$y][$xx] = match (true) {
                    $y === $yr || $y === $yr + 2 => lkHex(C_TAIL_EDGE),
                    $i % 2 === 0 && $xx === $c => lkHex(C_TAIL_PERF),
                    default => lkHex(C_TAIL),
                };
            }
        }
    }
}

// ---------------------------------------------------------------- frame build
/**
 * Build the CW×SH pixel grid for animation time $f. $f > F_ANIM is the
 * settled idle pose. $glintX null → no sweeping highlight on the wordmark.
 * @return list<list<?array>>
 */
function buildPixels(float $f, ?float $glintX = null): array
{
    $px = array_fill(0, SH, array_fill(0, CW, null));

    // motion: vinyl rolls in left→center (CW spin), reel right→center (CCW)
    $p1 = clamp01($f / 24.0);
    $cx1 = -13.0 + (VINYL_X + 13.0) * easeOutBack($p1);
    $a1 = 13.5 * ($cx1 + 13.0) + max(0.0, $f - 26.0) * 3.4;

    $p2 = clamp01(($f - 3.0) / 24.0);
    $e2 = easeOutBack($p2);
    $cx2 = (CW + 12.0) - (CW + 12.0 - REEL_X) * $e2;
    $a2 = -(13.5 * ((CW + 12.0) - $cx2) + max(0.0, $f - 29.0) * 3.24);

    // discs first, wordmark on top (the landing discs tuck behind the logo)
    drawDisc($px, $cx1, deg2rad($a1), 'vinyl');
    drawDisc($px, $cx2, deg2rad($a2), 'reel');
    $lp = clamp01(($e2 - 0.75) / 0.25);
    if ($lp > 0.0) {
        drawFilmTail($px, (int) round($cx2), $lp);
    }
    drawWordmark($px, $glintX);
    return $px;
}

/** @param list<list<?array>> $px */
function drawDisc(array &$px, float $cx, float $rad, string $kind): void
{
    $xLo = max(0, (int) floor($cx - R_DISC));
    $xHi = min(CW - 1, (int) ceil($cx + R_DISC));
    for ($y = 0; $y < SH; $y++) {
        $dy = $y - CY;
        if (abs($dy) > R_DISC + 0.5) {
            continue;
        }
        for ($x = $xLo; $x <= $xHi; $x++) {
            $dx = $x - $cx;
            if (abs($dx) > R_DISC + 0.5) {
                continue;
            }
            $d = sqrt($dx * $dx + $dy * $dy);
            if ($d > R_DISC) {
                continue;
            }
            $thC = atan2($dy, $dx);
            $thM = $thC - $rad;
            if ($kind === 'vinyl') {
                vinylPixel($d, $thM, rad2deg($thC), $px, $y, $x);
            } else {
                reelPixel($d, $thM, rad2deg($thC), $px, $y, $x);
            }
        }
    }
}

// ---------------------------------------------------------------- CLI
$write = in_array('--write', $argv, true);
$peek  = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--peek' && isset($argv[$i + 1])) {
        $peek = array_map('intval', explode(',', $argv[$i + 1]));
    }
}

$dir = __DIR__;

/** Glint sweeps the wordmark cols after the discs dock. */
function frameGlint(float $f): ?float
{
    if ($f < 26.0 || $f > 36.0) {
        return null;
    }
    return -1.0 + ($f - 26.0) * ((strlen(wordMask()[0]) + 3) / 10.0);
}

$tcFrames = [];
for ($f = 0.0; $f <= F_ANIM; $f += 1.0) {
    $tcFrames[] = lkEncode(lkHalfBlock(buildPixels($f, frameGlint($f))), 'tc');
}
$static = lkEncode(lkHalfBlock(buildPixels(F_ANIM + 2.0, null)), 'tc');

if ($peek !== null) {
    foreach ($peek as $pf) {
        $src = $pf <= F_ANIM ? $tcFrames[$pf] : $static;
        file_put_contents("$dir/peek-$pf.ansi", $src);
        fwrite(STDERR, "wrote peek-$pf.ansi\n");
    }
}

$anim = lkAnim($tcFrames, $static);

echo $static, "\n";
[$w, $h] = lkVerify($static, 'static');
lkCheckDepth($anim, 'tc', 'anim');
fwrite(STDERR, sprintf("static %dx%d, %d anim frames + final pose\n", $w, $h, count($tcFrames)));

if ($write) {
    [$sw, $sh2] = lkVerify($static, 'logo-vinyl-and-reel-tc');
    if ($sw > 80 || $sh2 > 14) {
        exit("canvas {$sw}x{$sh2} exceeds 80x14\n");
    }
    file_put_contents("$dir/logo-vinyl-and-reel-tc.ansi", $static);
    file_put_contents("$dir/logo-vinyl-and-reel-anim-tc.ansi", $anim);
    fwrite(STDERR, "wrote logo-vinyl-and-reel-tc.ansi (" . strlen($static) . "B)\n");
    fwrite(STDERR, "wrote logo-vinyl-and-reel-anim-tc.ansi (" . strlen($anim) . "B)\n");
}

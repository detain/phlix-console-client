<?php

declare(strict_types=1);

/**
 * generate-rotating-3d-wordmark — Phlix console-client logo, concept #19.
 *
 * A 3D-extruded "Phlix" wordmark spinning about its vertical axis: the letter
 * mask is the front face, five depth layers are obliquely projected behind it
 * (x offset = sin(theta), a slight upward-back lean so the slab keeps its
 * volume even square-on), z-sorted far-to-near. Lambert shading from a warm
 * amber key (upper left) + a cool slate fill, top-edge / baseline bevel, and a
 * specular band that sweeps across the letters as they turn past the light.
 * Below the baseline: a dim, vertically flipped, noise-dithered floor
 * reflection. The spin arrives square with a short overshoot and settles;
 * a soft glint idle pass runs before the clean static end frame.
 *
 * The whole thing lives in a 58 x 28 pixel buffer half-blocked to 14 terminal
 * rows (9 wordmark + floor band + 4 reflection). Deterministic: every random
 * number is lkNoise(x, y, SEED), so re-runs are byte-identical.
 *
 * Usage:
 *   php generate-rotating-3d-wordmark.php            # print static frame + stats
 *   php generate-rotating-3d-wordmark.php --write    # write all .ansi deliverables
 *   php generate-rotating-3d-wordmark.php --frame=N  # print one intro frame (stdout)
 *   php generate-rotating-3d-wordmark.php --anim     # emit full anim to stdout
 *   php generate-rotating-3d-wordmark.php --outro    # emit outro anim to stdout
 *
 * All writes stay inside this directory (no logos.jsonl, no path escapes).
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'rotating-3d-wordmark';
const SEED = 1937;

/** 14-row pixel masks ('#' = ink). Cap P, then h l i x. */
const FONT = [
    'P' => [
        '.######..',
        '#########',
        '##.....##',
        '##.....##',
        '##.....##',
        '##.....##',
        '#########',
        '.#######.',
        '.###.....',
        '.##......',
        '.##......',
        '.##......',
        '.##......',
        '.##......',
    ],
    'h' => [
        '##.......',
        '##.......',
        '##.......',
        '##.####..',
        '###...##.',
        '##....###',
        '##.....##',
        '##.....##',
        '##.....##',
        '##.....##',
        '##.....##',
        '##.....##',
        '##.....##',
        '##.....##',
    ],
    'l' => [
        '##..',
        '##..',
        '##..',
        '##..',
        '##..',
        '##..',
        '##..',
        '##..',
        '##..',
        '##..',
        '##..',
        '##..',
        '##..',
        '##..',
    ],
    'i' => [
        '.##.',
        '.##.',
        '....',
        '....',
        '.##.',
        '.##.',
        '.##.',
        '.##.',
        '.##.',
        '.##.',
        '.##.',
        '.##.',
        '.##.',
        '.##.',
    ],
    'x' => [
        '##....##',
        '##....##',
        '##....##',
        '.##..##.',
        '.##..##.',
        '..####..',
        '..####..',
        '.##..##.',
        '.##..##.',
        '##....##',
        '##....##',
        '##....##',
        '##....##',
        '##....##',
    ],
];

const PAGE = '#1a1b26';       // terminal backdrop the reflection fades into
const AMBER = '#ffb43a';      // warm key light on the face
const COOL = '#7d8ec4';       // cool fill bleeding in on the far side
const FILL_DEEP = '#38305a';  // tint the extrusion sides drift toward
const WHITE = '#ffffff';

const GRID_W = 58;            // pixel == column grid
const MASK_X0 = 8;            // mask left edge inside the grid at theta = 0
const CROWN = 3;              // px rows kept free above caps for the back lean
const MASK_H = 14;
const WORD_ROWS = CROWN + MASK_H + 1;        // 18 -> 9 terminal rows
const FLOOR_R = WORD_ROWS + 1;               // 1-bit floor rule (row 19)
const REFL_ROWS = 8;                         // 4 terminal rows of reflection
const GRID_H = WORD_ROWS + 2 + REFL_ROWS;    // 28 px -> exactly 14 cell rows

const DEPTH = 5;              // extrusion slices behind the face
const KX = 0.95;              // horizontal px per depth unit at sin(theta)=1
const EY = 0.62;              // upward-back px per depth unit (camera above)

const L_X = -0.394;           // normalized key direction (x,z components)
const L_Z = 0.919;

const SPIN_FRAMES = 44;       // angled -> square (easeOutBack overshoot)
const IDLE_FRAMES = 10;       // soft glint pass after the settle
const OUTRO_FRAMES = 26;      // square -> edge-on -> dissolve to nothing

const TH_START = -72.0;      // degrees at frame 0 (deeply foreshortened)

// ================================================================ GEOMETRY

/** @return array{0:list<string>,1:int,2:int} mask rows, width, height */
function mask(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    [$rows] = lkLayout(FONT, 'Phlix', letterGap: 2, wordGap: 4, margin: 0);
    return $cache = [$rows, strlen($rows[0]), count($rows)];
}

/** Center column of the face (fixed — the word spins about its own vertical axis). */
function centerX(): float
{
    [, $mw] = mask();
    return MASK_X0 + ($mw - 1) / 2;
}

/**
 * Foreshorten the mask to the current angle: each target pixel column
 * integrates 8 samples across the source span it projects onto, keeping
 * ink where the span is at least half covered. Rows never scale (y-axis spin).
 * @return list<list<bool>> [mask row][grid col]
 */
function faceMask(float $cosT): array
{
    [$rows, $mw, $mh] = mask();
    $cx = centerX();
    $mc = ($mw - 1) / 2;                                   // mask's own center column
    $ink = static function (int $r, float $u) use ($rows, $mw): bool {
        $c = (int) floor($u + 0.5);
        return $c >= 0 && $c < $mw && $rows[$r][$c] === '#';
    };
    $face = [];
    for ($v = 0; $v < $mh; $v++) {
        $line = [];
        for ($x = 0; $x < GRID_W; $x++) {
            $u0 = ($x - $cx) / $cosT + $mc - 0.5;
            $u1 = ($x + 1 - $cx) / $cosT + $mc - 0.5;
            $u0 = max($u0, -1.0);
            $u1 = min($u1, (float) $mw);
            $hits = 0;
            if ($u1 > $u0) {
                for ($s = 0; $s < 8; $s++) {
                    if ($ink($v, $u0 + ($u1 - $u0) * ($s + 0.5) / 8)) {
                        $hits++;
                    }
                }
            }
            $line[] = $hits >= 4;
        }
        $face[] = $line;
    }
    return $face;
}

// ================================================================ SHADING

/** Amber key across the face, cooled by the slate fill toward the right. */
function faceBase(int $x, int $v, float $diff): array
{
    [, $mw] = mask();
    $fill = 0.08 + 0.20 * max(0.0, min(1.0, ($x - MASK_X0) / $mw));
    $col = lkMix(lkHex(AMBER), lkHex(COOL), $fill);
    $col = lkScale($col, 0.62 + 0.44 * $diff);            // Lambert, key light
    $col = lkScale($col, 1.12 - 0.30 * ($v / (MASK_H - 1))); // vertical bevel
    return $col;
}

/** Specular band: mix toward white where |x - glintX| < gw. */
function glintize(array $col, int $x, float $gx, float $gw, float $gs): array
{
    if ($gs <= 0.0 || $gx < -40.0) {
        return $col;
    }
    $d = abs($x - $gx);
    return $d < $gw ? lkMix($col, lkHex(WHITE), pow(1 - $d / $gw, 1.8) * $gs) : $col;
}

// ================================================================ COMPOSITION

/**
 * One fully-shaded wordmark: depth slices painted far-to-near, then the
 * bevelled front face. Returns rows 0..WORD_ROWS-1 of the pixel buffer.
 * @return list<list<?array>>
 */
function renderWord(float $thetaDeg, float $gx, float $gw, float $gs): array
{
    $grid = array_fill(0, WORD_ROWS, array_fill(0, GRID_W, null));
    $vis = array_fill(0, WORD_ROWS, array_fill(0, GRID_W, false));
    $th = deg2rad($thetaDeg);
    $cosT = max(0.03, cos($th));
    $sinT = sin($th);
    $face = faceMask($cosT);
    $diff = max(0.0, $sinT * L_X + $cosT * L_Z);

    // extruded depth layers (also the silhouette crown from the back lean)
    $sideK = 0.72 + 0.42 * abs($sinT);                    // edge-lit when turned
    for ($z = DEPTH; $z >= 1; $z--) {
        $dx = (int) round($sinT * KX * $z);
        $dy = -(int) round(EY * $z);
        $k = $sideK * 0.78 * pow(0.84, $z - 1);
        for ($v = 0; $v < MASK_H; $v++) {
            for ($x = 0; $x < GRID_W; $x++) {
                if (!$face[$v][$x]) {
                    continue;
                }
                $px = $x + $dx;
                $py = CROWN + $v + $dy;
                if ($px < 0 || $px >= GRID_W || $py < 0 || $py >= WORD_ROWS) {
                    continue;
                }
                $col = lkScale(faceBase($x, $v, $diff), $k);
                $col = lkMix($col, lkHex(FILL_DEEP), 0.30 + 0.06 * $z);
                $grid[$py][$px] = glintize($col, $px, $gx, $gw, $gs * 0.45);
                $vis[$py][$px] = true;
            }
        }
    }

    // front face last (z-sort: nearest wins) with edge bevels
    for ($v = 0; $v < MASK_H; $v++) {
        for ($x = 0; $x < GRID_W; $x++) {
            if (!$face[$v][$x]) {
                continue;
            }
            $col = faceBase($x, $v, $diff);
            if ($v === 0 || !$face[$v - 1][$x]) {
                $col = lkMix($col, lkHex(WHITE), 0.34);   // lit top edge
            }
            if ($v === MASK_H - 1 || !$face[$v + 1][$x]) {
                $col = lkScale($col, 0.70);               // shaded baseline
            }
            $grid[CROWN + $v][$x] = glintize($col, $x, $gx, $gw, $gs);
            $vis[CROWN + $v][$x] = false;
        }
    }

    // the surviving slice strip above the caps is the slab's TOP face — the key
    // light rakes it, so brighten it warm; flank strips stay stepped-dark
    for ($y = 0; $y < CROWN; $y++) {
        for ($x = 0; $x < GRID_W; $x++) {
            if ($vis[$y][$x] && $grid[$y][$x] !== null) {
                $grid[$y][$x] = lkScale(lkMix($grid[$y][$x], [255, 236, 190], 0.22), 1.45);
            }
        }
    }
    return $grid;
}

/** 1-bit amber floor rule, strongest under the wordmark, dithered. */
function floorRow(): array
{
    $row = array_fill(0, GRID_W, null);
    $cx = centerX();
    [, $mw] = mask();
    for ($x = 0; $x < GRID_W; $x++) {
        $w = max(0.0, 1 - abs($x - $cx) / ($mw * 0.85));
        if (lkNoise($x, FLOOR_R, SEED) < 0.30 + 0.55 * $w) {
            $row[$x] = lkMix(lkHex(PAGE), [112, 132, 196], 0.14 + 0.30 * $w);
        }
    }
    return $row;
}

/**
 * Mirror of the word: vertically flipped, dimmed to ~30 %, dithered with
 * deterministic drop-outs, smeared one pixel left/right so it never reads
 * as a clean copy.
 * @param list<list<?array>> $word
 * @return list<list<?array>>  REFL_ROWS pixel rows
 */
function reflection(array $word, float $fade): array
{
    $refl = [];
    for ($r = 0; $r < REFL_ROWS; $r++) {
        $srcRow = CROWN + MASK_H - 1 - intdiv($r * MASK_H, REFL_ROWS);
        $line = array_fill(0, GRID_W, null);
        for ($x = 0; $x < GRID_W; $x++) {
            $nS = lkNoise($x, $r, SEED * 3);
            $xs = $x + ($nS > 0.66 ? 1 : ($nS < 0.12 ? -1 : 0));
            $src = $word[$srcRow][$xs] ?? $word[$srcRow][$x] ?? null;
            if ($src === null) {
                continue;
            }
            $keep = 0.92 - 0.42 * ($r / (REFL_ROWS - 1));
            if (lkNoise($x, $r, SEED * 11) > $keep * $fade) {
                continue;                                   // dithered holes
            }
            $dim = 0.48 * pow(1 - $r / REFL_ROWS, 1.2) * (0.80 + 0.40 * lkNoise($x, $r, SEED * 5));
            $line[$x] = lkScale($src, $dim * $fade);
        }
        $refl[] = $line;
    }
    return $refl;
}

/**
 * Assemble the 28-row pixel buffer (word + floor + reflection), apply the
 * outro fade/dissolve, half-block it, and encode truecolor.
 */
function renderFrame(float $theta, float $gx, float $gw, float $gs, float $fade = 1.0): string
{
    $word = renderWord($theta, $gx, $gw, $gs);
    if ($fade < 1.0) {
        for ($y = 0; $y < WORD_ROWS; $y++) {
            for ($x = 0; $x < GRID_W; $x++) {
                if ($word[$y][$x] !== null) {
                    if (lkNoise($x, $y, SEED + 77) > $fade * 0.92) {
                        $word[$y][$x] = null;               // speckle away
                    } else {
                        $word[$y][$x] = lkScale($word[$y][$x], $fade);
                    }
                }
            }
        }
    }
    $px = $word;
    $px[] = array_fill(0, GRID_W, null);                    // gap above the rule
    $px[] = $fade > 0.02
        ? array_map(static fn (?array $c): ?array => $c === null ? null : lkScale($c, $fade), floorRow())
        : array_fill(0, GRID_W, null);
    foreach (reflection($word, $fade) as $line) {
        $px[] = $line;
    }
    return lkEncode(lkHalfBlock($px), 'tc');
}

// ================================================================ TIMELINES

/** easeOutBack with a mild overshoot — the wordmark swings square and settles. */
function easeOutBack(float $p, float $c1 = 1.15): float
{
    $c3 = $c1 + 1;
    return 1 + $c3 * pow($p - 1, 3) + $c1 * pow($p - 1, 2);
}

/** @return list<string> intro frames (spin + settle + idle glint) */
function introFrames(): array
{
    [, $mw] = mask();
    $frames = [];
    for ($i = 0; $i < SPIN_FRAMES; $i++) {
        $p = $i / (SPIN_FRAMES - 1);
        $theta = TH_START * (1 - easeOutBack($p));
        $gx = -10 + ($mw + 20) * min(1.0, $p / 0.85);       // flash past the light
        $frames[] = renderFrame($theta, $gx, 3.2, 0.80);
    }
    for ($j = 0; $j < IDLE_FRAMES; $j++) {
        $u = $j / (IDLE_FRAMES - 1);
        $frames[] = renderFrame(0.0, -8 + ($mw + 16) * $u, 2.2, 0.42);
    }
    return $frames;
}

function staticFrame(): string
{
    return renderFrame(0.0, -99.0, 0.0, 0.0);
}

/** @return list<string> outro frames (spin away edge-on, dissolve to zero) */
function outroFrames(): array
{
    [, $mw] = mask();
    $frames = [];
    for ($i = 0; $i < OUTRO_FRAMES; $i++) {
        $p = $i / (OUTRO_FRAMES - 1);
        $theta = -88.0 * pow($p, 1.5);
        $fade = max(0.0, 1 - max(0.0, ($p - 0.52) / 0.48));
        $gx = -10 + ($mw + 20) * min(1.0, $p * 2.2);
        $frames[] = renderFrame($theta, $gx, 3.0, 0.65 * $fade, $fade);
    }
    return $frames;
}

function blankFrame(): string
{
    return lkEncode(lkHalfBlock(array_fill(0, GRID_H, array_fill(0, GRID_W, null))), 'tc');
}

// ================================================================ WRITE / CLI

$write = in_array('--write', $argv, true);
foreach ($argv as $arg) {
    if (preg_match('/^--frame=(\d+)$/', $arg, $m) && (int) $m[1] < count(introFrames())) {
        echo introFrames()[(int) $m[1]];
        exit(0);
    }
}

$static = staticFrame();
$intro = introFrames();
$anim = lkAnim($intro, $static);
$outroAnim = lkAnim(outroFrames(), blankFrame());

if (in_array('--anim', $argv, true)) {
    echo $anim;
    exit(0);
}
if (in_array('--outro', $argv, true)) {
    echo $outroAnim;
    exit(0);
}

// self-gates: legal widths, truecolor purity, anim must end on the static frame
[$w, $h] = lkVerify($static, SLUG);
lkCheckDepth($anim, 'tc', SLUG . '/anim');
lkCheckDepth($outroAnim, 'tc', SLUG . '/outro');
lkCheckDepth($static, 'tc', SLUG . '/static');
if (str_ends_with($anim, $static . "\e[?25h")) {
    $tailOk = 'yes';
} else {
    throw new RuntimeException('anim does not end on the static frame');
}
$nSep = preg_match_all('/\e\[\d+A\r/', $anim);
$framesTotal = count($intro) + 1;
if ($nSep !== $framesTotal - 1) {
    throw new RuntimeException("separator count $nSep != frames-1");
}
if ($h > 14 || $w > 80) {
    throw new RuntimeException("frame {$w}x{$h} exceeds budget");
}

fwrite(STDERR, "$w x $h  truecolor ok  frames=$framesTotal  tail==static:$tailOk\n");

if (!$write) {
    echo $static;
    exit(0);
}

$dir = __DIR__;
$slug = SLUG;
file_put_contents("$dir/logo-$slug-tc.ansi", $static);
file_put_contents("$dir/logo-$slug-anim-tc.ansi", $anim);
file_put_contents("$dir/logo-$slug-outro-tc.ansi", $outroAnim);
fwrite(STDERR, "wrote logo-$slug-tc.ansi, logo-$slug-anim-tc.ansi, logo-$slug-outro-tc.ansi\n");

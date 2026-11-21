<?php

declare(strict_types=1);

/**
 * generate-clapperboard-snap — "Phlix" as a film clapperboard.
 *
 * Story (animation): the slate's clapper stick is raised with its diagonal
 * chalk stripes open → it SNAPS shut (contact flash) → the whole screen
 * SHAKEs (impact) → the board FLIPS over on its horizontal centre line
 * (vertical-scale illusion: the slate face pinches to a thin edge, then the
 * other side swings into view) → it settles to the clean final frame: a closed
 * clapperboard whose face carries the "Phlix" wordmark.
 *
 * House idioms: half-block (▀/▄/█) shading, a restrained slate-black /
 * chalk-white / warm-amber palette, simple vertical-scale for the flip.
 *
 *   php generate-clapperboard-snap.php            preview + verify to stdout
 *   php generate-clapperboard-snap.php --write    write the .ansi files
 *   php logo-play.php logo-clapperboard-snap-anim-tc.ansi 90
 *   php logo-preview.php logo-clapperboard-snap-tc.ansi logo-clapperboard-snap-tc.png
 */

require __DIR__ . '/logo-kit.php';

const SLUG = 'clapperboard-snap';

// Canvas (fixed for every frame so lkAnim's cursor-up redraw lands cleanly).
const W_PX = 52;          // 52 px  = 52 columns
const H_PX = 22;          // 22 px  = 11 terminal rows

// Board geometry (px space, y down).
const BX0 = 2;            // board left
const BX1 = 49;           // board right (inclusive)
const FACE_TOP = 10;      // first face row (below the stick)
const FACE_BOT = 21;      // last face row
const STICK_TOP = 5;      // closed stick top row
const STICK_H = 5;        // stick thickness (rows STICK_TOP..9)
const STICK_MAX_RISE = 4; // open right-end lift (px)

// Restrained palette.
const C_SLATE   = '#12141A'; // board body (near-black)
const C_SLATE_UP= '#20242E'; // lit top of the face
const C_PANEL   = '#23272F'; // info panel fill
const C_EDGE    = '#3A404C'; // borders / card edge
const C_CHALK   = '#F2EEDF'; // stripes / logo highlight
const C_CHALK_D = '#8C887A'; // dim info text
const C_AMBER   = '#FF9A3C'; // warm accent (hinge + logo)
const C_AMBER_D = '#D9611E'; // amber base (gradient bottom)
const C_BASE    = '#0A0B0F'; // footer bar under the board

// Phlix wordmark: 5 module rows, widths P4 h4 l2 i1 x4.
const FONT = [
    'P' => ['####', '#   ', '### ', '#   ', '#   '],
    'h' => ['#   ', '####', '#   ', '#   ', '#   '],
    'l' => [' #', ' #', ' #', ' #', '##'],
    'i' => ['#', ' ', '#', '#', '#'],
    'x' => ['#  #', '#  #', ' ## ', ' # #', '#  #'],
];
const WORD = 'Phlix';
const SCALE = 2; // each font module → SCALE px

// ================================================================ colour helpers

function hex(string $h): array
{
    return lkHex($h);
}

function clip(array $c): array
{
    return [
        max(0, min(255, (int) round($c[0]))),
        max(0, min(255, (int) round($c[1]))),
        max(0, min(255, (int) round($c[2]))),
    ];
}

// ================================================================ face / stick bitmaps (world px coords)

/** Slate backdrop for a face row: vertical brightness gradient, near-black. */
function faceRowBase(int $y): array
{
    $t = ($y - FACE_TOP) / max(1, (FACE_BOT - FACE_TOP)); // 0 top → 1 bottom
    return lkMix(hex(C_SLATE_UP), hex(C_SLATE), min(1.0, $t * 1.25));
}

/** FRONT face — a production slate: header band + ruled info lines + ticks. */
function buildFront(): array
{
    $b = array_fill(0, H_PX, array_fill(0, W_PX, null));
    // board border
    for ($x = BX0; $x <= BX1; $x++) {
        $b[FACE_TOP][$x] = hex(C_EDGE);
        $b[FACE_BOT][$x] = hex(C_EDGE);
    }
    for ($y = FACE_TOP + 1; $y < FACE_BOT; $y++) {
        $b[$y][BX0] = hex(C_EDGE);
        $b[$y][BX1] = hex(C_EDGE);
    }
    // header band (a raised strip below the stick) in panel colour
    for ($x = BX0 + 1; $x <= BX1 - 1; $x++) {
        $b[11][$x] = hex(C_PANEL);
    }
    // a warm accent block at the left of the header (a slate "brand tag")
    for ($x = BX0 + 2; $x <= BX0 + 5; $x++) {
        $b[11][$x] = lkMix(hex(C_AMBER), hex(C_AMBER_D), 0.4);
    }
    // ruled info lines with a short amber tick where the "value" sits
    foreach ([14, 17, 20] as $ly) {
        for ($x = BX0 + 3; $x <= BX1 - 3; $x++) {
            $b[$ly][$x] = lkMix(faceRowBase($ly), hex(C_CHALK_D), 0.32);
        }
        for ($x = BX0 + 3; $x <= BX0 + 6; $x++) {
            $b[$ly][$x] = hex(C_CHALK_D);
        }
        for ($x = BX1 - 8; $x <= BX1 - 3; $x++) {
            $b[$ly][$x] = lkMix(faceRowBase($ly), hex(C_AMBER), 0.55);
        }
    }
    return $b;
}

/** BACK face — the reveal: "Phlix" wordmark on a lit slate. */
function buildBack(): array
{
    $b = array_fill(0, H_PX, array_fill(0, W_PX, null));
    for ($x = BX0; $x <= BX1; $x++) {
        $b[FACE_TOP][$x] = hex(C_EDGE);
        $b[FACE_BOT][$x] = hex(C_EDGE);
    }
    for ($y = FACE_TOP + 1; $y < FACE_BOT; $y++) {
        $b[$y][BX0] = hex(C_EDGE);
        $b[$y][BX1] = hex(C_EDGE);
    }
    // wordmark layout — inflate the 5-row font on both axes by SCALE
    $wordPixH = count(FONT['P']) * SCALE;      // 5 * 2 = 10 px tall (fills face interior 11..20)

    $cols = [];
    foreach (['P', 'h', 'l', 'i', 'x'] as $L) {
        $g = FONT[$L];
        $rawW = (int) max(array_map('strlen', $g));
        $cw = $rawW * SCALE;
        $block = [];
        foreach ($g as $row) {
            $str = str_pad($row, $rawW, ' ');
            $line = '';
            foreach (str_split($str) as $m) {
                $line .= $m === ' ' ? '..' : '##';
            }
            for ($k = 0; $k < SCALE; $k++) {
                $block[] = $line;                  // vertical inflation
            }
        }
        $cols[] = ['w' => $cw, 'block' => $block];
    }
    $gap = SCALE;
    $total = array_sum(array_map(static fn (array $c): int => $c['w'], $cols)) + $gap * (count($cols) - 1);
    $ox = intdiv(BX0 + BX1 + 1 - $total, 2);
    $originY = 11;                             // rows 11..20, between the borders + above the rule

    $top = hex(C_AMBER);
    $bot = hex(C_AMBER_D);
    $x = $ox;
    foreach ($cols as $li => $c) {
        $isDot = ($li === 3); // the 'i' stem glows chalk-white for legibility
        foreach ($c['block'] as $gy => $row) {
            foreach (str_split($row) as $gx => $mk) {
                if ($mk !== '#') {
                    continue;
                }
                $t = $gy / ($wordPixH - 1);
                $col = lkMix($top, $bot, $t);
                $col = lkShade($col, 0.28 - 0.55 * $t);      // lit top, saturated base
                if ($isDot) {
                    $col = lkMix($col, hex(C_CHALK), 0.55);
                }
                $px = $x + $gx;
                $py = $originY + $gy;
                if ($py >= 0 && $py < H_PX && $px >= 0 && $px < W_PX) {
                    $b[$py][$px] = clip($col);
                }
            }
        }
        $x += $c['w'] + $gap;
    }
    return $b;
}

/** Flat stick bitmap (closed): diagonal chalk stripes, rows STICK_TOP..9. */
function buildStick(): array
{
    $b = array_fill(0, H_PX, array_fill(0, W_PX, null));
    $period = 8;
    $sw = 4;
    for ($y = STICK_TOP; $y < STICK_TOP + STICK_H; $y++) {
        $v = $y - STICK_TOP;
        for ($x = BX0 - 1; $x <= BX1 + 1; $x++) {
            $u = $x - (BX0 - 1);
            $on = ($u - $v) % $period;
            if ($on < 0) {
                $on += $period;
            }
            $b[$y][$x] = $on < $sw ? clip(hex(C_CHALK)) : clip(lkMix(hex(C_SLATE_UP), [0, 0, 0], 0.25));
        }
    }
    return $b;
}

// Small uppercase bitmap removed — the front face is a clean ruled slate.

// ================================================================ per-frame render

/**
 * @param int   $rise   stick open lift (px, 0..STICK_MAX_RISE)
 * @param float $flash  contact-brighten 0..1
 * @param int   $shakeY board px offset (±)
 * @param float $sc     flip vertical scale |cos θ| (1 = flat)
 * @param int   $face   0 = front, 1 = back (during flip)
 */
function frame(array $F, array $Bk, array $St, int $rise, float $flash, int $shakeY, float $sc, int $face): string
{
    return lkEncode(lkHalfBlock(frameWorld($F, $Bk, $St, $rise, $flash, $shakeY, $sc, $face)), 'tc');
}

function frameWorld(array $F, array $Bk, array $St, int $rise, float $flash, int $shakeY, float $sc, int $face): array
{
    $P = array_fill(0, H_PX, array_fill(0, W_PX, null));
    if ($sc < 0.16) {
        $mid = (int) round((FACE_TOP + FACE_BOT) / 2 + $shakeY);
        if ($mid >= 0 && $mid < H_PX) {
            for ($x = BX0; $x <= BX1; $x++) {
                $P[$mid][$x] = clip(hex(C_EDGE));
            }
        }
    } else {
        // 2. board face — sampled with vertical scale about its centre line
        $bc = (FACE_TOP + FACE_BOT) / 2;
        for ($yb = FACE_TOP; $yb <= FACE_BOT; $yb++) {
            $off = $yb - $bc;
            $sy = (int) round($bc + $off / $sc);           // source row (no vertical mirror)
            if ($sy < FACE_TOP) {
                $sy = FACE_TOP;
            } elseif ($sy > FACE_BOT) {
                $sy = FACE_BOT;
            }
            $ty = $yb + $shakeY;
            if ($ty < 0 || $ty >= H_PX) {
                continue;
            }
            $src = $face === 0 ? $F : $Bk;
            for ($x = BX0; $x <= BX1; $x++) {
                if ($src[$sy][$x] !== null) {
                    $P[$ty][$x] = $src[$sy][$x];
                }
            }
        }
    }

    // 3. base bar under the board (drawn after so it stays during the pinch)
    for ($x = BX0; $x <= BX1; $x++) {
        for ($y = FACE_BOT + 1; $y < H_PX; $y++) {
            $P[$y][$x] = clip(hex(C_BASE));
        }
    }

    // 4. clapper stick (rotates about the left hinge; sits above the board)
    for ($x = BX0 - 1; $x <= BX1 + 1; $x++) {
        $u = $x - (BX0 - 1);
        $r = (int) round($rise * $u / max(1, (BX1 + 1) - (BX0 - 1)));
        if ($r > STICK_MAX_RISE) {
            $r = STICK_MAX_RISE;
        }
        for ($y = STICK_TOP - $r; $y < STICK_TOP + STICK_H - $r; $y++) {
            if ($y < 0 || $y >= H_PX) {
                continue;
            }
            $v = $y + $r - STICK_TOP;
            $col = $St[$v + STICK_TOP][$x] ?? null;
            if ($col === null) {
                continue;
            }
            if ($flash > 0) {
                $col = lkMix($col, [255, 255, 255], $flash * 0.8);
            }
            $P[$y][$x] = clip($col);
        }
    }

    // 5. hinge dot at the pivot (left end of the stick), amber
    $hy = STICK_TOP + STICK_H - 1 - $rise;
    if ($hy >= 0 && $hy < H_PX) {
        $P[$hy][BX0] = clip(hex(C_AMBER));
        if ($hy + 1 < H_PX) {
            $P[$hy + 1][BX0] = lkMix(hex(C_AMBER), hex(C_AMBER_D), 0.5);
        }
    }

    // 6. contact sparks at the clap (two small chalk flecks off the right tip)
    if ($flash > 0.6) {
        $tipX = BX1 + 1;
        $tipY = STICK_TOP - $rise;
        if ($tipY >= 0 && $tipY < H_PX && $tipX + 1 < W_PX) {
            $P[$tipY][$tipX + 1] = clip(lkMix(hex(C_CHALK), [255, 255, 255], 0.5));
        }
    }

    return $P;
}

// ================================================================ animation timeline

/**
 * @return list<array{int,float,int,float,int}> [rise, flash, shakeY, sc, face]
 */
function animFrames(): array
{
    $f = [];
    $ease = static fn (float $t): float => $t * $t * (3 - 2 * $t);

    // (a) open hold, gentle lift
    $open = [14, 16, 17, 16, 15, 14];
    foreach ($open as $o) {
        $f[] = [$o, 0.0, 0, 1.0, 0];
    }
    // (b) clap down (ease-in, accelerating)
    $clap = [14, 12, 9, 6, 3, 1, 0, 0];
    foreach ($clap as $o) {
        $f[] = [$o, 0.0, 0, 1.0, 0];
    }
    // (c) contact flash
    $f[] = [0, 1.0, 0, 1.0, 0];
    $f[] = [0, 0.7, 0, 1.0, 0];
    $f[] = [0, 0.3, 0, 1.0, 0];
    // (d) screen shake (impact rattle decaying)
    $shake = [0, 1, -1, 1, 0, 0];
    foreach ($shake as $s) {
        $f[] = [0, 0.0, $s, 1.0, 0];
    }
    // (e) flip over (|cos|), first half front, second half back
    $flip = [1.0, 0.87, 0.64, 0.38, 0.18, 0.05, 0.05, 0.18, 0.38, 0.64, 0.87, 1.0];
    foreach ($flip as $i => $sc) {
        $face = $i < 6 ? 0 : 1;
        $f[] = [0, 0.0, 0, max(0.05, $sc), $face];
    }
    // (f) settle on the logo face
    foreach ([14, 15, 16] as $sc) {
        $f[] = [0, 0.0, 0, min(1.0, $sc / 16), 1];
    }
    return $f;
}

// ================================================================ local writer (no external side effects)

function writeLogoLocal(string $dir, string $file, string $ansi, string $depth): array
{
    [$w, $h] = lkVerify(lkLastFrame($ansi), $file);
    lkCheckDepth($ansi, $depth, $file);
    file_put_contents("$dir/$file", $ansi);
    return [$w, $h];
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dir = __DIR__;

$F = buildFront();
$Bk = buildBack();
$St = buildStick();

$poses = [];
foreach ($argv as $a) {
    if (preg_match('/^--poses=(.+)$/', $a, $m)) {
        $poses = array_map('intval', explode(',', $m[1]));
    }
}

if ($poses) {
    $tl = animFrames();
    foreach ($poses as $idx) {
        if (!isset($tl[$idx])) {
            echo "-- pose $idx out of range\n";
            continue;
        }
        [$rise, $flash, $shakeY, $sc, $face] = $tl[$idx];
        echo "===== pose $idx  (rise=$rise flash=$flash shakeY=$shakeY sc=$sc face=$face) =====\n";
        $W = frameWorld($F, $Bk, $St, $rise, $flash, $shakeY, $sc, $face);
        for ($y = 0; $y < H_PX; $y += 2) {
            $line = '';
            for ($x = 0; $x < W_PX; $x++) {
                $a = $W[$y][$x];
                $b = $W[$y + 1][$x];
                if ($a === null && $b === null) { $line .= ' '; }
                elseif ($a !== null && $b !== null) {
                    $da = ($a[0] + $a[1] + $a[2]) / 3;
                    $db = ($b[0] + $b[1] + $b[2]) / 3;
                    $line .= ($da > 150 || $db > 150) ? '#' : '+';
                } else { $line .= $a !== null ? '^' : '_'; }
            }
            echo rtrim($line), "\n";
        }
        echo "\n";
    }
    exit;
}

if (in_array('--dump-back', $argv, true)) {
    $bp = buildBack();
    for ($y = 0; $y < H_PX; $y++) {
        $line = '';
        for ($x = 0; $x < W_PX; $x++) {
            $line .= $bp[$y][$x] === null ? '.' : '#';
        }
        printf("%2d: %s\n", $y, $line);
    }
    exit;
}

// Static final frame = closed board, logo face.
$static = frame($F, $Bk, $St, 0, 0.0, 0, 1.0, 1);

if (!$write) {
    echo $static, "\n";
}
$frames = [];
foreach (animFrames() as [$rise, $flash, $shakeY, $sc, $face]) {
    $frames[] = frame($F, $Bk, $St, $rise, $flash, $shakeY, $sc, $face);
}
$anim = lkAnim($frames, $static);

if ($write) {
    [$sw, $sh] = writeLogoLocal($dir, 'logo-clapperboard-snap-tc.ansi', $static, 'tc');
    [$aw, $ah] = writeLogoLocal($dir, 'logo-clapperboard-snap-anim-tc.ansi', $anim, 'tc');
    fwrite(STDERR, "[tc] static {$sw}x{$sh} ok | anim {$aw}x{$ah} " . (count($frames) + 1) . " frames\n");
} else {
    // preview: show the animation frames' key poses
    foreach ([0, 6, 9, 15, 27, count($frames) - 1] as $idx) {
        echo $frames[$idx];
        echo "---- pose $idx ----\n";
    }
    [$sw, $sh] = lkVerify($static, 'static');
    fwrite(STDERR, "[tc] {$sw}x{$sh} ok  (" . (count($frames) + 1) . " frames, " . round((count($frames) + 1) * 0.09, 1) . "s)\n");
}
